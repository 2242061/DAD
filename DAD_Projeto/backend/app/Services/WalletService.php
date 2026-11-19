<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class WalletService
{
    public const EXCHANGE_RATE = 100;

    public const FEE_BPS = 1000;

    public function rules(): array
    {
        return ['currency' => 'Damas', 'symbol' => 'DM', 'coins_per_euro' => self::EXCHANGE_RATE, 'fee_percent' => self::FEE_BPS / 100, 'minimum_euros' => 1, 'maximum_euros' => 99, 'simulated' => true];
    }

    public function wallet(User $user): Wallet
    {
        return Wallet::firstOrCreate(['user_id' => $user->id]);
    }

    /** Integer arithmetic; gateway amounts are whole euros and fees are retained in coins. */
    public function quote(string $kind, int $euros): array
    {
        $gross = $euros * self::EXCHANGE_RATE;
        $fee = intdiv($gross * self::FEE_BPS, 10000);

        return ['amount_euros' => $euros, 'exchange_rate' => self::EXCHANGE_RATE, 'fee_bps' => self::FEE_BPS, 'gross_coins' => $gross, 'fee_coins' => $fee,
            'net_coins' => $kind === 'deposit' ? $gross - $fee : 0, 'debit_coins' => $kind === 'withdrawal' ? $gross + $fee : 0];
    }

    public function expireProcessing(Wallet $wallet): void
    {
        WalletTransaction::where('wallet_id', $wallet->id)->where('status', 'processing')->where('created_at', '<', now()->subMinutes(2))
            ->update(['status' => 'review', 'failure_code' => 'interrupted']);
    }

    public function perform(User $user, string $kind, array $input): WalletTransaction
    {
        $wallet = $this->wallet($user);
        $hash = hash_hmac('sha256', json_encode([$kind, $input['type'], $input['reference'], (int) $input['value']], JSON_THROW_ON_ERROR), config('app.key'));
        [$operation, $created] = DB::transaction(function () use ($wallet, $input, $kind, $hash): array {
            Wallet::whereKey($wallet->id)->update(['updated_at' => now()]);
            $this->expireProcessing($wallet);
            $old = WalletTransaction::where('wallet_id', $wallet->id)->where('idempotency_key', $input['idempotency_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Este pedido já foi usado com outros dados. Atualiza a carteira antes de continuar.');

                return [$old, false];
            }
            abort_if(WalletTransaction::where('wallet_id', $wallet->id)->whereIn('status', ['processing', 'review'])->exists(), 409, 'Há uma operação a aguardar confirmação. Consulta o histórico antes de fazer outra.');
            $quote = $this->quote($kind, (int) $input['value']);
            if ($kind === 'withdrawal') {
                $changed = Wallet::whereKey($wallet->id)->whereRaw('balance - reserved >= ?', [$quote['debit_coins']])->increment('reserved', $quote['debit_coins']);
                abort_unless($changed === 1, 409, 'Não tens Damas disponíveis para o levantamento e a comissão.');
            }
            $reference = $input['reference'];
            $masked = $input['type'] === 'PAYPAL' ? mb_substr($reference, 0, 1).'•••@•••' : '•••• '.substr($reference, -4);
            $operation = WalletTransaction::create($quote + ['wallet_id' => $wallet->id, 'idempotency_key' => $input['idempotency_key'], 'request_hash' => $hash,
                'kind' => $kind, 'status' => 'processing', 'method' => $input['type'], 'reference' => $reference, 'masked_reference' => $masked]);

            return [$operation, true];
        });
        if (! $created) {
            return $operation;
        }
        $status = null;
        try {
            $response = Http::acceptJson()->asJson()->withoutRedirecting()->connectTimeout(2)->timeout(7)
                ->post(rtrim(config('services.payments.url'), '/').($kind === 'deposit' ? '/api/payments' : '/api/withdraws'), [
                    'type' => $operation->method, 'reference' => $operation->reference, 'value' => $operation->amount_euros,
                ]);
            $status = $response->status();
            if ($status === 201 || $status === 422) {
                return $this->settle($operation, $status === 201 ? 'completed' : 'failed', $status);
            }

            return $this->review($operation, $status, 'gateway_unavailable');
        } catch (Throwable) {
            // The gateway has no status or idempotency endpoint: never automatically retry an uncertain external effect.
            Log::warning('Simulated wallet operation requires review.', ['operation' => $operation->id]);

            return $this->review($operation, $status, $status === 201 ? 'local_commit_failed' : 'uncertain');
        }
    }

    private function review(WalletTransaction $operation, ?int $gatewayStatus, string $code): WalletTransaction
    {
        WalletTransaction::whereKey($operation->id)->where('status', 'processing')->update(['status' => 'review', 'gateway_status' => $gatewayStatus, 'failure_code' => $code]);

        return $operation->refresh();
    }

    /** The wallet lock serializes settlement and prevents partial ledger or balance updates. */
    public function settle(WalletTransaction $operation, string $status, ?int $gatewayStatus = null, ?User $actor = null, ?string $reason = null): WalletTransaction
    {
        return DB::transaction(function () use ($operation, $status, $gatewayStatus, $actor, $reason): WalletTransaction {
            Wallet::whereKey($operation->wallet_id)->update(['updated_at' => now()]);
            $operation->refresh();
            if (in_array($operation->status, ['completed', 'failed'], true)) {
                return $operation;
            }
            if ($actor) {
                abort_unless($operation->status === 'review', 409, 'Esta operação não está pendente de verificação.');
            }
            $wallet = Wallet::findOrFail($operation->wallet_id);
            $before = $wallet->balance;
            if ($operation->kind === 'withdrawal') {
                if ($wallet->reserved < $operation->debit_coins || $wallet->balance < $operation->debit_coins) {
                    throw new LogicException('Wallet reservation invariant violated.');
                }
                $wallet->reserved -= $operation->debit_coins;
                if ($status === 'completed') {
                    $wallet->balance -= $operation->debit_coins;
                }
            } elseif ($status === 'completed') {
                $wallet->balance += $operation->net_coins;
            }
            $wallet->save();
            if ($status === 'completed') {
                WalletEntry::create(['wallet_id' => $wallet->id, 'transaction_id' => $operation->id, 'delta' => $wallet->balance - $before, 'previous_balance' => $before, 'resulting_balance' => $wallet->balance]);
            }
            $operation->forceFill(['status' => $status, 'gateway_status' => $gatewayStatus ?? $operation->gateway_status, 'failure_code' => $status === 'failed' ? 'gateway_rejected' : null, 'finished_at' => now(), 'resolved_by' => $actor?->id, 'resolution_reason' => $reason])->save();
            if ($actor) {
                $owner = User::withTrashed()->findOrFail($wallet->user_id);
                app(AccountSecurity::class)->record($actor, $owner, 'wallet.resolved', ['transaction_id' => $operation->id, 'status' => $status, 'reason' => $reason]);
            }

            return $operation;
        });
    }

    public function data(WalletTransaction $operation, ?WalletEntry $entry = null): array
    {
        return ['id' => $operation->id, 'kind' => $operation->kind, 'status' => $operation->status, 'method' => $operation->method, 'reference' => $operation->masked_reference,
            'amount_euros' => $operation->amount_euros, 'gross_coins' => $operation->gross_coins, 'fee_coins' => $operation->fee_coins, 'net_coins' => $operation->net_coins, 'debit_coins' => $operation->debit_coins,
            'delta' => $entry?->delta, 'resulting_balance' => $entry?->resulting_balance, 'failure_code' => $operation->failure_code, 'created_at' => $operation->created_at->toIso8601String(), 'finished_at' => $operation->finished_at?->toIso8601String(),
            'resolved' => $operation->resolved_by !== null];
    }
}

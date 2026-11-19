<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Models\WalletTransaction;
use Illuminate\Support\Str;

class CompetitionWallet
{
    public const RANKED_FEE = 50;

    public const TOURNAMENT_FEE = 100;

    public const PODIUM_PERCENTAGES = [50, 30, 20];

    /** Called inside the transaction that changes the competition state. */
    public function move(int $userId, int $delta, string $kind, string $reference, ?string $insufficientFunds = null): void
    {
        if ($delta === 0) {
            return;
        }

        $wallet = Wallet::firstOrCreate(['user_id' => $userId]);
        $query = Wallet::whereKey($wallet->id);
        if ($delta < 0) {
            $query->whereRaw('balance - reserved >= ?', [-$delta]);
        }
        abort_unless($query->increment('balance', $delta) === 1, 409, $insufficientFunds ?? 'Saldo disponível insuficiente para pagar a entrada de '.(-$delta).' Damas.');

        $balance = $wallet->refresh()->balance;
        $key = (string) Str::uuid();
        $operation = WalletTransaction::create([
            'wallet_id' => $wallet->id, 'idempotency_key' => $key, 'request_hash' => hash('sha256', $key),
            'kind' => $kind, 'status' => 'completed', 'method' => 'CLUB', 'reference' => $reference,
            'masked_reference' => $reference, 'amount_euros' => 0, 'exchange_rate' => WalletService::EXCHANGE_RATE,
            'fee_bps' => 0, 'gross_coins' => abs($delta), 'fee_coins' => 0,
            'net_coins' => max(0, $delta), 'debit_coins' => max(0, -$delta), 'finished_at' => now(),
        ]);
        WalletEntry::create([
            'wallet_id' => $wallet->id, 'transaction_id' => $operation->id, 'delta' => $delta,
            'previous_balance' => $balance - $delta, 'resulting_balance' => $balance,
        ]);
    }
}

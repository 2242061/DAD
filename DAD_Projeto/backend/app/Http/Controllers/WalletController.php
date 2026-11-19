<?php

namespace App\Http\Controllers;

use App\Http\Requests\WalletOperationRequest;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Models\WalletTransaction;
use App\Services\AccountSecurity;
use App\Services\CompetitionWallet;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $wallet = $this->wallets->wallet($request->user());
        $this->wallets->expireProcessing($wallet);
        $wallet->refresh();
        $rows = WalletTransaction::where('wallet_id', $wallet->id)->latest('created_at')->latest('id')->paginate(10);
        $entries = WalletEntry::whereIn('transaction_id', $rows->getCollection()->pluck('id'))->get()->keyBy('transaction_id');
        $completed = WalletTransaction::where('wallet_id', $wallet->id)->where('status', 'completed');

        return response()->json(['wallet' => ['balance' => $wallet->balance, 'reserved' => $wallet->reserved, 'available' => $wallet->balance - $wallet->reserved],
            'rules' => $this->wallets->rules(), 'stats' => ['deposited' => (int) (clone $completed)->where('kind', 'deposit')->sum('net_coins'), 'withdrawn' => (int) (clone $completed)->where('kind', 'withdrawal')->sum('debit_coins'), 'fees' => (int) $completed->sum('fee_coins')],
            'data' => $rows->getCollection()->map(fn (WalletTransaction $op): array => $this->wallets->data($op, $entries[$op->id] ?? null)),
            'unresolved' => WalletTransaction::where('wallet_id', $wallet->id)->whereIn('status', ['processing', 'review'])->exists(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function requestStatus(Request $request, string $key): JsonResponse
    {
        $wallet = $this->wallets->wallet($request->user());
        $this->wallets->expireProcessing($wallet);
        $op = WalletTransaction::where('wallet_id', $wallet->id)->where('idempotency_key', $key)->firstOrFail();

        return response()->json(['data' => $this->wallets->data($op, WalletEntry::where('transaction_id', $op->id)->first())])->header('Cache-Control', 'private, no-store');
    }

    public function deposit(WalletOperationRequest $request): JsonResponse
    {
        return $this->operation($request, 'deposit');
    }

    public function withdraw(WalletOperationRequest $request): JsonResponse
    {
        return $this->operation($request, 'withdrawal');
    }

    private function operation(WalletOperationRequest $request, string $kind): JsonResponse
    {
        $op = $this->wallets->perform($request->user(), $kind, $request->validated());

        return response()->json(['data' => $this->wallets->data($op, WalletEntry::where('transaction_id', $op->id)->first())], in_array($op->status, ['processing', 'review'], true) ? 202 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function audit(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'status' => ['sometimes', 'in:processing,review,completed,failed'], 'kind' => ['sometimes', 'in:deposit,withdrawal,entry,refund,prize,adjustment'], 'q' => ['sometimes', 'string', 'max:100']]);
        WalletTransaction::where('status', 'processing')->where('created_at', '<', now()->subMinutes(2))->update(['status' => 'review', 'failure_code' => 'interrupted']);
        $query = WalletTransaction::query()->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->input('kind')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->where('id', $request->input('q'))->orWhereIn('wallet_id', Wallet::whereIn('user_id', User::withTrashed()->where('name', 'like', '%'.$request->input('q').'%')->select('id'))->select('id'))));
        $rows = $query->latest('created_at')->latest('id')->paginate(20);
        $entries = WalletEntry::whereIn('transaction_id', $rows->getCollection()->pluck('id'))->get()->keyBy('transaction_id');
        $owners = Wallet::whereIn('id', $rows->getCollection()->pluck('wallet_id'))->pluck('user_id', 'id');
        $names = User::withTrashed()->whereIn('id', $owners->values())->pluck('name', 'id');
        $data = $rows->getCollection()->map(fn (WalletTransaction $op): array => $this->wallets->data($op, $entries[$op->id] ?? null) + ['user_id' => $owners[$op->wallet_id], 'name' => $names[$owners[$op->wallet_id]] ?? 'Conta eliminada', 'gateway_status' => $op->gateway_status, 'resolution_reason' => $op->resolution_reason, 'resolved_by' => $op->resolved_by]);
        $completed = WalletTransaction::where('status', 'completed');

        return response()->json(['data' => $data, 'rules' => $this->wallets->rules(), 'stats' => ['balance' => (int) Wallet::sum('balance'), 'reserved' => (int) Wallet::sum('reserved'),
            'fees' => (int) $completed->sum('fee_coins'), 'tournament_revenue' => (int) Tournament::where('status', 'finished')->sum('site_revenue'), 'payments_euros' => (int) (clone $completed)->where('kind', 'deposit')->sum('amount_euros'), 'withdrawals_euros' => (int) (clone $completed)->where('kind', 'withdrawal')->sum('amount_euros'), 'review' => WalletTransaction::where('status', 'review')->count()],
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function userBalance(User $user): JsonResponse
    {
        $wallet = Wallet::where('user_id', $user->id)->first();

        return response()->json(['name' => $user->name, 'balance' => $wallet?->balance ?? 0,
            'reserved' => $wallet?->reserved ?? 0])->header('Cache-Control', 'private, no-store');
    }

    public function adjust(Request $request, User $user, CompetitionWallet $movement, AccountSecurity $audit): JsonResponse
    {
        $data = $request->validate([
            'balance' => ['required', 'integer', 'between:0,1000000000'],
            'expected_balance' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'current_password' => ['required', 'string', 'current_password:web'],
        ]);
        DB::transaction(function () use ($request, $user, $movement, $audit, $data): void {
            $wallet = $this->wallets->wallet($user);
            Wallet::whereKey($wallet->id)->update(['updated_at' => now()]);
            $wallet->refresh();
            abort_unless($wallet->balance === (int) $data['expected_balance'], 409, 'O saldo mudou. Atualiza a página antes de ajustar.');
            $balance = (int) $data['balance'];
            abort_if($balance < $wallet->reserved, 409, 'O novo saldo não pode ser inferior ao valor reservado.');
            abort_if($balance === $wallet->balance, 409, 'Indica um saldo diferente do atual.');
            $movement->move($user->id, $balance - $wallet->balance, 'adjustment', 'Ajuste administrativo');
            $audit->record($request->user(), $user, 'wallet.adjusted', [
                'previous_balance' => $wallet->balance, 'balance' => $balance, 'reason' => $data['reason'],
            ]);
        });

        return $this->userBalance($user);
    }

    public function resolve(Request $request, WalletTransaction $transaction): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'current_password:web'], 'status' => ['required', 'in:completed,failed'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $this->wallets->expireProcessing(Wallet::findOrFail($transaction->wallet_id));
        $transaction->refresh();
        abort_unless($transaction->status === 'review' || $transaction->status === $data['status'], 409, 'Esta operação não está pendente de verificação ou já foi resolvida de outra forma.');
        $op = $this->wallets->settle($transaction, $data['status'], actor: $request->user(), reason: $data['reason']);

        return response()->json(['data' => $this->wallets->data($op, WalletEntry::where('transaction_id', $op->id)->first())])->header('Cache-Control', 'private, no-store');
    }
}

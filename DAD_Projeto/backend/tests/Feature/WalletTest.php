<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private int $gatewayStatus = 201;

    private bool $disconnected = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://127.0.0.1:5173');
        Http::preventStrayRequests();
        Http::fake(['dad-payments-api.vercel.app/*' => function () {
            if ($this->disconnected) {
                throw new ConnectionException('timeout');
            }

            return Http::response([], $this->gatewayStatus);
        }]);
    }

    private function actor(?User $user): static
    {
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->withSession(['auth_version' => 0]);
        if ($user) {
            $this->actingAs($user->fresh(), 'web');
        }

        return $this;
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['idempotency_key' => Str::uuid()->toString(), 'type' => 'MB', 'reference' => '45634-123456789', 'value' => 10], $overrides);
    }

    public function test_guest_blocked_and_revoked_sessions_cannot_access_or_operate_wallet(): void
    {
        $this->getJson('/api/wallet')->assertUnauthorized();
        $this->postJson('/api/wallet/deposits', $this->input())->assertUnauthorized();
        $user = User::factory()->create(['blocked_at' => now()]);
        $this->actor($user)->getJson('/api/wallet')->assertForbidden();
        $revoked = User::factory()->create(['auth_version' => 1]);
        $this->actor($revoked)->getJson('/api/wallet')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_new_wallet_is_empty_and_views_and_pagination_do_not_create_credits(): void
    {
        $user = User::factory()->create();
        $this->actor($user)->getJson('/api/wallet')->assertOk()->assertJsonPath('wallet.balance', 0)->assertJsonPath('wallet.available', 0)->assertJsonPath('rules.coins_per_euro', 100)->assertJsonPath('rules.fee_percent', 10)->assertJsonPath('meta.total', 0);
        $this->getJson('/api/wallet')->assertOk();
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->getJson('/api/wallet?page=0')->assertUnprocessable();
    }

    public function test_successful_deposit_credits_net_only_with_fee_and_immutable_ledger(): void
    {
        $user = User::factory()->create();
        $response = $this->actor($user)->postJson('/api/wallet/deposits', $this->input())->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.fee_coins', 100)->assertJsonPath('data.delta', 900)->assertJsonPath('data.resulting_balance', 900);
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->getJson('/api/wallet')->assertOk()->assertJsonPath('wallet.balance', 900)->assertJsonPath('stats.fees', 100)->assertJsonPath('stats.deposited', 900);
        $this->assertSame(0, $user->fresh()->rank_points);
        $this->assertStringNotContainsString('45634-123456789', $response->getContent());
        $raw = DB::table('wallet_transactions')->first();
        $this->assertStringNotContainsString('45634-123456789', $raw->reference);
        $this->assertSame('45634-123456789', WalletTransaction::first()->reference);
        Http::assertSent(fn ($request) => $request->url() === 'https://dad-payments-api.vercel.app/api/payments' && $request->data() === ['type' => 'MB', 'reference' => '45634-123456789', 'value' => 10]);
    }

    public function test_identical_request_and_service_settlement_are_idempotent_and_modified_payload_conflicts(): void
    {
        $user = User::factory()->create();
        $input = $this->input();
        $id = $this->actor($user)->postJson('/api/wallet/deposits', $input)->assertOk()->json('data.id');
        $this->postJson('/api/wallet/deposits', $input)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/wallet/deposits', array_replace($input, ['value' => 11]))->assertConflict();
        app(WalletService::class)->settle(WalletTransaction::find($id), 'completed', 201);
        $this->assertSame(900, Wallet::first()->balance);
        $this->assertDatabaseCount('wallet_entries', 1);
        Http::assertSentCount(1);
    }

    public function test_withdrawal_debits_amount_plus_fee_and_sends_integer_euros_to_gateway(): void
    {
        $user = User::factory()->create();
        $this->actor($user)->postJson('/api/wallet/deposits', $this->input(['value' => 20]))->assertOk();
        $this->postJson('/api/wallet/withdrawals', $this->input(['value' => 10, 'current_password' => 'password']))->assertOk()->assertJsonPath('data.delta', -1100)->assertJsonPath('data.fee_coins', 100)->assertJsonPath('data.resulting_balance', 700);
        $this->getJson('/api/wallet')->assertOk()->assertJsonPath('wallet.balance', 700)->assertJsonPath('wallet.reserved', 0)->assertJsonPath('stats.withdrawn', 1100)->assertJsonPath('stats.fees', 300);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/withdraws') && $request['value'] === 10);
    }

    public function test_insufficient_funds_and_wrong_password_never_contact_gateway_or_create_operations(): void
    {
        $user = User::factory()->create();
        $this->actor($user)->postJson('/api/wallet/withdrawals', $this->input(['value' => 1, 'current_password' => 'wrong']))->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/api/wallet/withdrawals', $this->input(['value' => 1, 'current_password' => 'password']))->assertConflict();
        $this->assertDatabaseCount('wallet_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_second_withdrawal_cannot_spend_money_already_reserved_for_first(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 200]);
        $this->disconnected = true;
        $input = $this->input(['value' => 1, 'current_password' => 'password']);
        $id = $this->actor($user)->postJson('/api/wallet/withdrawals', $input)->assertStatus(202)->assertJsonPath('data.status', 'review')->json('data.id');
        $this->assertSame(110, $wallet->fresh()->reserved);
        $this->postJson('/api/wallet/withdrawals', $this->input(['value' => 1, 'current_password' => 'password']))->assertConflict();
        $this->postJson('/api/wallet/withdrawals', $input)->assertStatus(202)->assertJsonPath('data.id', $id);
        $this->getJson('/api/wallet')->assertOk()->assertJsonPath('wallet.balance', 200)->assertJsonPath('wallet.available', 90)->assertJsonPath('unresolved', true);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_definitive_gateway_rejection_preserves_balance_releases_reservation_and_does_not_retry(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $this->gatewayStatus = 422;
        $input = $this->input(['value' => 1, 'current_password' => 'password']);
        $this->actor($user)->postJson('/api/wallet/withdrawals', $input)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_code', 'gateway_rejected');
        $this->postJson('/api/wallet/withdrawals', $input)->assertOk();
        $this->assertSame(1000, $wallet->fresh()->balance);
        $this->assertSame(0, $wallet->fresh()->reserved);
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->getJson('/api/wallet')->assertOk()->assertJsonPath('stats.fees', 0)->assertJsonPath('unresolved', false);
        Http::assertSentCount(1);
    }

    public function test_unknown_response_is_not_assumed_failed_and_does_not_release_or_credit(): void
    {
        $user = User::factory()->create();
        $this->gatewayStatus = 503;
        $input = $this->input();
        $this->actor($user)->postJson('/api/wallet/deposits', $input)->assertStatus(202)->assertJsonPath('data.status', 'review');
        $this->postJson('/api/wallet/deposits', $input)->assertStatus(202);
        $this->assertSame(0, Wallet::first()->balance);
        Http::assertSentCount(1);
    }

    public function test_interrupted_request_becomes_review_after_two_minutes_without_resending(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 200, 'reserved' => 110]);
        WalletTransaction::factory()->create(['wallet_id' => $wallet->id, 'kind' => 'withdrawal', 'net_coins' => 0, 'debit_coins' => 110]);
        $this->travel(3)->minutes();
        $this->actor($user)->getJson('/api/wallet')->assertOk()->assertJsonPath('data.0.status', 'review')->assertJsonPath('data.0.failure_code', 'interrupted')->assertJsonPath('wallet.available', 90);
        Http::assertNothingSent();
    }

    public function test_ledger_write_failure_after_gateway_success_rolls_back_credit_and_records_review(): void
    {
        $user = User::factory()->create();
        WalletEntry::creating(fn () => throw new RuntimeException('simulated ledger failure'));
        try {
            $this->actor($user)->postJson('/api/wallet/deposits', $this->input())->assertStatus(202)->assertJsonPath('data.status', 'review')->assertJsonPath('data.failure_code', 'local_commit_failed');
        } finally {
            WalletEntry::flushEventListeners();
        }
        $this->assertSame(0, Wallet::first()->balance);
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertSame(201, WalletTransaction::first()->gateway_status);
        Http::assertSentCount(1);
    }

    public function test_admin_balance_adjustment_preserves_reserves_and_records_the_change_once(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 200, 'reserved' => 110]);
        $admin = User::factory()->create(['role' => 'admin']);
        $path = '/api/admin/wallet/users/'.$user->id;
        $input = ['balance' => 150, 'expected_balance' => 200, 'reason' => 'Correção de saldo de teste', 'current_password' => 'password'];

        $this->actor($user)->patchJson($path, $input)->assertForbidden();
        $this->actor($admin)->patchJson($path, array_replace($input, ['balance' => 109]))->assertConflict();
        $this->patchJson($path, $input)->assertOk()->assertJsonPath('balance', 150)->assertJsonPath('reserved', 110);
        $this->patchJson($path, $input)->assertConflict();
        $this->assertSame(150, $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_entries', ['wallet_id' => $wallet->id, 'delta' => -50]);
        $this->assertSame(1, AuditLog::where('action', 'wallet.adjusted')->count());
    }

    public function test_only_verified_admin_with_password_and_reason_can_resolve_uncertain_operation_once(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 200, 'reserved' => 110]);
        $op = WalletTransaction::factory()->create(['wallet_id' => $wallet->id, 'status' => 'review', 'kind' => 'withdrawal', 'debit_coins' => 110, 'net_coins' => 0, 'fee_coins' => 10, 'amount_euros' => 1]);
        $path = '/api/admin/wallet/transactions/'.$op->id.'/resolve';
        $input = ['status' => 'completed', 'reason' => 'Confirmação manual do serviço simulado', 'current_password' => 'password'];
        $this->actor($user)->postJson($path, $input)->assertForbidden();
        $admin = User::factory()->unverified()->create(['role' => 'admin']);
        $this->actor($admin)->postJson($path, $input)->assertForbidden();
        $admin->forceFill(['email_verified_at' => now()])->save();
        $this->actor($admin)->postJson($path, array_replace($input, ['current_password' => 'wrong']))->assertUnprocessable();
        $this->postJson($path, array_replace($input, ['reason' => 'short']))->assertUnprocessable();
        $this->postJson($path, $input)->assertOk()->assertJsonPath('data.delta', -110);
        $this->postJson($path, $input)->assertOk();
        $this->postJson($path, array_replace($input, ['status' => 'failed']))->assertConflict();
        $this->assertSame(90, $wallet->fresh()->balance);
        $this->assertSame(0, $wallet->fresh()->reserved);
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->assertSame(1, AuditLog::where('action', 'wallet.resolved')->count());
        Http::assertNothingSent();
    }

    public function test_admin_can_confirm_failure_to_release_reservation_and_cannot_resolve_fresh_processing(): void
    {
        $wallet = Wallet::factory()->create(['balance' => 200, 'reserved' => 110]);
        $op = WalletTransaction::factory()->create(['wallet_id' => $wallet->id, 'status' => 'processing', 'kind' => 'withdrawal', 'debit_coins' => 110, 'net_coins' => 0]);
        $admin = User::factory()->create(['role' => 'admin']);
        $input = ['status' => 'failed', 'reason' => 'Falha confirmada no simulador externo', 'current_password' => 'password'];
        $this->actor($admin)->postJson('/api/admin/wallet/transactions/'.$op->id.'/resolve', $input)->assertConflict();
        $this->travel(3)->minutes();
        $this->postJson('/api/admin/wallet/transactions/'.$op->id.'/resolve', $input)->assertOk();
        $this->assertSame(200, $wallet->fresh()->balance);
        $this->assertSame(0, $wallet->fresh()->reserved);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_history_is_private_and_admin_has_filtered_masked_audit_and_totals(): void
    {
        $owner = User::factory()->create(['name' => 'Titular da carteira']);
        $this->actor($owner)->postJson('/api/wallet/deposits', $this->input())->assertOk();
        $other = User::factory()->create();
        $this->actor($other)->getJson('/api/wallet')->assertOk()->assertJsonPath('wallet.balance', 0)->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/wallet/transactions')->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actor($admin)->getJson('/api/admin/wallet/transactions?status=completed&kind=deposit&q=Titular')->assertOk()->assertJsonPath('stats.fees', 100)->assertJsonPath('stats.payments_euros', 10)->assertJsonPath('meta.total', 1);
        $this->assertStringNotContainsString('45634-123456789', $response->getContent());
        $this->assertStringNotContainsString('request_hash', $response->getContent());
        $this->getJson('/api/admin/wallet/transactions?status=review')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_request_lookup_is_private_and_same_idempotency_key_can_be_used_by_different_users(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $input = $this->input();
        $path = '/api/wallet/requests/'.$input['idempotency_key'];
        $id = $this->actor($first)->postJson('/api/wallet/deposits', $input)->assertOk()->json('data.id');
        $this->getJson($path)->assertOk()->assertJsonPath('data.id', $id);
        $this->actor($second)->getJson($path)->assertNotFound();
        $this->postJson('/api/wallet/deposits', $input)->assertOk();
        $this->getJson($path)->assertOk();
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseCount('wallet_entries', 2);
        Http::assertSentCount(2);
    }

    public function test_failure_saving_withdrawal_ledger_keeps_full_reservation_and_balance(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        WalletEntry::creating(fn () => throw new RuntimeException('simulated ledger failure'));
        try {
            $this->actor($user)->postJson('/api/wallet/withdrawals', $this->input(['value' => 1, 'current_password' => 'password']))->assertStatus(202)->assertJsonPath('data.status', 'review');
        } finally {
            WalletEntry::flushEventListeners();
        }
        $this->assertSame(1000, $wallet->fresh()->balance);
        $this->assertSame(110, $wallet->fresh()->reserved);
        $this->assertDatabaseCount('wallet_entries', 0);
        Http::assertSentCount(1);
    }

    public static function invalidReferences(): array
    {
        return [['MBWAY', '812345678'], ['MBWAY', '9157853450'], ['PAYPAL', 'invalid'], ['IBAN', 'PT123'], ['MB', '12345678901234'], ['VISA', '5123456789012345'], ['UNKNOWN', 'anything']];
    }

    #[DataProvider('invalidReferences')]
    public function test_method_reference_rules_are_validated_before_contacting_gateway(string $type, string $reference): void
    {
        $this->actor(User::factory()->create())->postJson('/api/wallet/deposits', $this->input(['type' => $type, 'reference' => $reference]))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_gateway_test_references_are_sent_instead_of_pre_rejected_and_all_methods_are_supported(): void
    {
        $user = User::factory()->create();
        $this->actor($user);
        foreach (['MBWAY' => '901645932', 'PAYPAL' => 'xx.john@example.test', 'IBAN' => 'XX50123456781234567812349', 'MB' => '95634-123456789', 'VISA' => '4021567812345678'] as $type => $reference) {
            $this->postJson('/api/wallet/deposits', $this->input(['type' => $type, 'reference' => $reference, 'value' => 1]))->assertOk();
        }
        Http::assertSentCount(5);
    }

    public function test_amount_and_client_supplied_balance_are_rejected_and_one_euro_rounding_is_exact(): void
    {
        $this->actor(User::factory()->create());
        foreach ([0, 100, 1.5, -1] as $value) {
            $this->postJson('/api/wallet/deposits', $this->input(['value' => $value]))->assertUnprocessable()->assertJsonValidationErrors('value');
        }
        $this->postJson('/api/wallet/deposits', $this->input(['balance' => 9999]))->assertUnprocessable();
        $this->postJson('/api/wallet/deposits', $this->input(['value' => 1]))->assertOk()->assertJsonPath('data.fee_coins', 10)->assertJsonPath('data.delta', 90);
        Http::assertSentCount(1);
    }
}

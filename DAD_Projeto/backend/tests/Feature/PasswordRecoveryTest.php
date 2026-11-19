<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://127.0.0.1:5173');
        Notification::fake();
    }

    private function payload(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'Recovered2026!', 'password_confirmation' => 'Recovered2026!'];
    }

    public function test_recovery_does_not_reveal_whether_an_email_exists(): void
    {
        $user = User::factory()->create();
        $known = $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk()->json();
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertArrayNotHasKey('token', $known);
    }

    public function test_a_reset_token_is_hashed_single_use_and_revokes_existing_sessions(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $this->assertNotSame($token, DB::table('password_reset_tokens')->where('email', $user->email)->value('token'));
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->postJson('/api/auth/reset-password', $this->payload($user, $token))->assertOk();
        $this->assertTrue(Hash::check('Recovered2026!', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->postJson('/api/auth/reset-password', $this->payload($user, $token))
            ->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_expired_and_invalid_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $this->postJson('/api/auth/reset-password', $this->payload($user, 'wrong-token'))
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->travel(61)->minutes();
        $this->postJson('/api/auth/reset-password', $this->payload($user, $token))
            ->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_blocked_and_deleted_accounts_cannot_recover_access(): void
    {
        $blocked = User::factory()->create(['blocked_at' => now()]);
        $deleted = User::factory()->create();
        $token = Password::broker()->createToken($blocked);
        $deleted->delete();
        foreach ([$blocked, $deleted] as $user) {
            $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
            Notification::assertNotSentTo($user, ResetPassword::class);
        }
        $this->postJson('/api/auth/reset-password', $this->payload($blocked, $token))->assertUnprocessable();
    }

    public function test_recovery_requests_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk();
        }
        $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.test'])->assertTooManyRequests();
    }

    public function test_reset_links_use_the_configured_frontend_not_the_request_host(): void
    {
        $user = User::factory()->create();
        $message = (new ResetPassword('some-token'))->toMail($user);
        $this->assertStringStartsWith(config('app.frontend_url').'/#/repor-palavra-passe?', $message->actionUrl);
    }
}

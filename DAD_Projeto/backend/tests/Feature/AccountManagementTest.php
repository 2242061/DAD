<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://127.0.0.1:5173');
        Notification::fake();
    }

    public function test_a_player_can_edit_their_name_but_cannot_change_their_role(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->patchJson('/api/account/profile', ['name' => 'Novo nome', 'email' => $user->email])
            ->assertOk()->assertJsonPath('data.name', 'Novo nome');
        $this->patchJson('/api/account/profile', ['name' => 'Novo nome', 'email' => $user->email, 'role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertSame(UserRole::Player, $user->fresh()->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile.updated', 'actor_id' => $user->id]);
    }

    public function test_changing_email_requires_the_current_password_and_a_new_verification(): void
    {
        $user = User::factory()->create(['password' => 'Current2026!']);
        $payload = ['name' => $user->name, 'email' => ' NEW@example.test '];
        $this->actingAs($user, 'web')->patchJson('/api/account/profile', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/account/profile', $payload + ['current_password' => 'Wrong2026!'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/account/profile', $payload + ['current_password' => 'Current2026!'])
            ->assertOk()->assertJsonPath('data.email', 'new@example.test')->assertJsonPath('data.email_verified', false);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_an_email_cannot_be_taken_from_another_account(): void
    {
        $user = User::factory()->create(['password' => 'Current2026!']);
        $other = User::factory()->create();
        $this->actingAs($user, 'web')->patchJson('/api/account/profile', ['name' => $user->name, 'email' => $other->email, 'current_password' => 'Current2026!'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_password_change_requires_the_old_password_and_revokes_sessions_and_tokens(): void
    {
        $user = User::factory()->create(['password' => 'Current2026!']);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('personal_access_tokens')->insert(['tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => 'old', 'token' => hash('sha256', 'old-token')]);
        $payload = ['password' => 'NewPassword2026!', 'password_confirmation' => 'NewPassword2026!'];
        $this->actingAs($user, 'web')->putJson('/api/account/password', $payload + ['current_password' => 'Wrong2026!'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/account/password', $payload + ['current_password' => 'Current2026!'])->assertNoContent();
        $this->assertTrue(Hash::check('NewPassword2026!', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertGuest('web');
        $log = DB::table('audit_logs')->where('action', 'password.changed')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('Current2026!', $log->details);
        $this->assertStringNotContainsString('NewPassword2026!', $log->details);
    }

    public function test_a_player_can_delete_their_account_only_with_explicit_confirmation(): void
    {
        $user = User::factory()->create(['password' => 'Current2026!']);
        $this->actingAs($user, 'web')->deleteJson('/api/account', ['current_password' => 'Current2026!'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->deleteJson('/api/account', ['current_password' => 'Current2026!', 'confirmation' => 'ELIMINAR'])->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertGuest('web');
    }

    public function test_an_admin_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin, 'web')->deleteJson('/api/account')->assertForbidden();
        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
    }

    public function test_guests_cannot_change_account_data(): void
    {
        $this->patchJson('/api/account/profile')->assertUnauthorized();
        $this->putJson('/api/account/password')->assertUnauthorized();
        $this->deleteJson('/api/account')->assertUnauthorized();
    }

    public function test_old_session_versions_are_rejected_even_if_a_session_record_survives(): void
    {
        $user = User::factory()->create(['auth_version' => 2]);
        $this->actingAs($user, 'web')->withSession(['auth_version' => 1])->getJson('/api/auth/me')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_login_records_the_current_session_version(): void
    {
        $user = User::factory()->create(['auth_version' => 3, 'password' => 'Current2026!']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Current2026!'])
            ->assertOk()->assertSessionHas('auth_version', 3);
        $this->getJson('/api/auth/me')->assertOk();
    }

    public function test_changing_email_removes_reset_links_for_the_previous_address(): void
    {
        $user = User::factory()->create(['password' => 'Current2026!']);
        $oldEmail = $user->email;
        Password::broker()->createToken($user);
        $this->actingAs($user, 'web')->patchJson('/api/account/profile', [
            'name' => $user->name, 'email' => 'changed@example.test', 'current_password' => 'Current2026!',
        ])->assertOk();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
        $this->getJson('/api/auth/me')->assertOk();
    }
}

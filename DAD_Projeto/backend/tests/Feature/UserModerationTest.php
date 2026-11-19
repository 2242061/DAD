<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://127.0.0.1:5173');
        Notification::fake();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'password' => 'Admin2026!']);
    }

    private function confirmation(): array
    {
        return ['current_password' => 'Admin2026!', 'reason' => 'Teste de moderação'];
    }

    public function test_blocking_revokes_sessions_and_prevents_login_until_unblocked(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['password' => 'Player2026!']);
        DB::table('sessions')->insert(['id' => 'victim-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($admin, 'web')->postJson("/api/admin/users/{$user->id}/block", $this->confirmation())->assertOk();
        $this->assertNotNull($user->fresh()->blocked_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'victim-session']);
        $this->postJson("/api/admin/users/{$user->id}/unblock", $this->confirmation())->assertOk();
        $this->assertNull($user->fresh()->blocked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.blocked', 'actor_id' => $admin->id, 'target_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.unblocked']);
    }

    public function test_an_already_authenticated_blocked_user_is_rejected(): void
    {
        $user = User::factory()->create(['blocked_at' => now()]);
        $this->actingAs($user, 'web')->getJson('/api/auth/me')->assertForbidden()->assertJsonPath('code', 'account_unavailable');
        $this->assertGuest('web');
    }

    public function test_blocked_and_deleted_users_cannot_login(): void
    {
        $blocked = User::factory()->create(['blocked_at' => now(), 'password' => 'Player2026!']);
        $deleted = User::factory()->create(['password' => 'Player2026!']);
        $deleted->delete();
        foreach ([$blocked, $deleted] as $user) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Player2026!'])->assertUnprocessable();
        }
        $this->assertGuest('web');
    }

    public function test_admin_actions_require_the_actors_password(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->actingAs($admin, 'web')->postJson("/api/admin/users/{$user->id}/block", ['reason' => 'Teste de bloqueio', 'current_password' => 'Wrong2026!'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertNull($user->fresh()->blocked_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admins_cannot_block_delete_or_demote_themselves(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web')->postJson("/api/admin/users/{$admin->id}/block", $this->confirmation())->assertForbidden();
        $this->deleteJson("/api/admin/users/{$admin->id}", $this->confirmation())->assertForbidden();
        $this->patchJson("/api/admin/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'role' => 'player', 'current_password' => 'Admin2026!'])->assertForbidden();
        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
        $this->assertNull($admin->fresh()->blocked_at);
        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
    }

    public function test_deletion_is_recoverable_and_filters_and_audit_preserve_the_record(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['name' => 'Membro recuperável']);
        $this->actingAs($admin, 'web')->deleteJson("/api/admin/users/{$user->id}", $this->confirmation())->assertOk();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->getJson('/api/admin/overview?status=deleted&q=recuper')->assertOk()->assertJsonPath('users.0.id', $user->id);
        $this->postJson("/api/admin/users/{$user->id}/restore", $this->confirmation())->assertOk();
        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
        $this->getJson('/api/admin/audit-logs')->assertOk()->assertJsonPath('data.0.action', 'user.restored');
    }

    public function test_an_admin_can_edit_a_users_role_and_invalidates_their_sessions(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'old-role-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($admin, 'web')->patchJson("/api/admin/users/{$user->id}", ['name' => 'Novo administrador', 'email' => $user->email, 'role' => 'admin', 'current_password' => 'Admin2026!'])
            ->assertOk()->assertJsonPath('data.role', 'admin');
        $this->assertDatabaseMissing('sessions', ['id' => 'old-role-session']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.updated']);
    }

    public function test_players_cannot_moderate_accounts_or_read_audit_logs(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/admin/audit-logs')->assertForbidden();
        $this->patchJson("/api/admin/users/{$other->id}")->assertForbidden();
        $this->postJson("/api/admin/users/{$other->id}/block")->assertForbidden();
        $this->deleteJson("/api/admin/users/{$other->id}")->assertForbidden();
        $this->postJson("/api/admin/users/{$other->id}/restore")->assertForbidden();
    }

    public function test_audit_entries_do_not_contain_passwords_or_reset_tokens(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->actingAs($admin, 'web')->patchJson("/api/admin/users/{$user->id}", ['name' => 'Nome atualizado', 'email' => $user->email, 'role' => 'player', 'current_password' => 'Admin2026!'])->assertOk();
        $body = $this->getJson('/api/admin/audit-logs')->assertOk()->getContent();
        $this->assertStringNotContainsString('Admin2026!', $body);
        $this->assertStringNotContainsString('remember_token', $body);
        $this->assertStringNotContainsString('current_password', $body);
    }

    public function test_a_stale_admin_request_cannot_moderate_after_losing_permissions(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        User::whereKey($admin->id)->update(['role' => 'player']);
        $this->actingAs($admin, 'web')->postJson("/api/admin/users/{$user->id}/block", $this->confirmation())->assertForbidden();
        $this->assertNull($user->fresh()->blocked_at);
    }

    public function test_admin_mutations_require_a_verified_email(): void
    {
        $admin = User::factory()->unverified()->create(['role' => UserRole::Admin, 'password' => 'Admin2026!']);
        $user = User::factory()->create();
        $this->actingAs($admin, 'web')->postJson("/api/admin/users/{$user->id}/block", $this->confirmation())
            ->assertForbidden()->assertJsonPath('code', 'email_unverified');
        $this->assertNull($user->fresh()->blocked_at);
    }
}

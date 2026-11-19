<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://127.0.0.1:5173');
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Maria Silva',
            'email' => 'maria@example.test',
            'password' => 'Damas2026!',
            'password_confirmation' => 'Damas2026!',
        ], $overrides);
    }

    public function test_a_visitor_can_register_as_a_player_and_is_signed_in(): void
    {
        $this->postJson('/api/auth/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('data.role', 'player')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $user = User::sole();
        $this->assertSame(UserRole::Player, $user->role);
        $this->assertTrue(Hash::check('Damas2026!', $user->password));
        $this->assertAuthenticatedAs($user, 'web');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
    }

    public function test_registration_normalizes_email_and_rejects_duplicates(): void
    {
        User::factory()->create(['email' => 'maria@example.test']);
        $this->postJson('/api/auth/register', $this->registration(['email' => ' MARIA@example.test ']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_cannot_choose_an_admin_role(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['role' => 'admin']))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_requires_a_strong_matching_password(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['password_confirmation' => 'Different2026']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/auth/register', $this->registration(['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_user_can_login_with_a_normalized_email(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.test', 'password' => 'Damas2026!']);
        $this->postJson('/api/auth/login', ['email' => ' MARIA@example.test ', 'password' => 'Damas2026!'])
            ->assertOk()->assertJsonPath('data.name', $user->name);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_invalid_credentials_are_rejected_without_exposing_which_field_is_wrong(): void
    {
        User::factory()->create(['email' => 'maria@example.test']);
        $this->postJson('/api/auth/login', ['email' => 'maria@example.test', 'password' => 'Incorrect123'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'O email ou a palavra-passe estão incorretos.');
        $this->assertGuest('web');
    }

    public function test_repeated_login_attempts_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => 'missing@example.test', 'password' => 'Incorrect123'])
                ->assertUnprocessable();
        }
        $this->postJson('/api/auth/login', ['email' => 'missing@example.test', 'password' => 'Incorrect123'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_a_guest_cannot_read_a_profile_or_logout(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }

    public function test_an_unknown_bearer_token_cannot_authenticate_a_visitor(): void
    {
        $this->withHeader('Authorization', 'Bearer invalid-token')
            ->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_authentication_requires_a_first_party_spa_request(): void
    {
        $this->withoutHeader('Origin')->postJson('/api/auth/login', [
            'email' => 'maria@example.test', 'password' => 'Damas2026!',
        ])->assertForbidden();
    }

    public function test_an_unsafe_spa_request_without_a_csrf_token_is_rejected(): void
    {
        $this->app->instance('env', 'local');
        $this->postJson('/api/auth/register', $this->registration())->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_passwords_exceeding_the_bcrypt_byte_limit_are_validation_errors(): void
    {
        $password = str_repeat('á', 36).'1';
        $this->postJson('/api/auth/register', $this->registration([
            'password' => $password, 'password_confirmation' => $password,
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_logout_ends_the_authenticated_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->postJson('/api/auth/logout')->assertNoContent();
        $this->assertGuest('web');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }
}

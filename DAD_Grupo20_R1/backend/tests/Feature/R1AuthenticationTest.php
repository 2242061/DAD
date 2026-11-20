<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class R1AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_consulta_utilizador_e_logout(): void
    {
        User::factory()->create(['email' => 'teste@example.test', 'password' => 'EntregaDAD2026!']);

        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', [
            'email' => 'teste@example.test', 'password' => 'errada',
        ])->assertUnprocessable();

        $token = $this->postJson('/api/auth/login', [
            'email' => 'teste@example.test', 'password' => 'EntregaDAD2026!',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('email', 'teste@example.test')
            ->assertJsonMissingPath('password');

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_token_expirado_deixa_de_dar_acesso(): void
    {
        User::factory()->create(['email' => 'expirado@example.test', 'password' => 'EntregaDAD2026!']);
        $token = $this->postJson('/api/auth/login', [
            'email' => 'expirado@example.test', 'password' => 'EntregaDAD2026!',
        ])->assertOk()->json('token');

        $this->assertNotNull(PersonalAccessToken::first()->expires_at);
        PersonalAccessToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_endpoints_dos_tutoriais_continuam_disponiveis(): void
    {
        User::factory()->create(['email' => 'tutorial@example.test', 'password' => 'Password2026!']);
        $this->getJson('/api/users/me')->assertUnauthorized();
        $resposta = $this->postJson('/api/login', [
            'email' => 'tutorial@example.test', 'password' => 'Password2026!',
        ])->assertOk()->assertJsonPath('user.email', 'tutorial@example.test');

        $token = $resposta->json('token');
        $this->withToken($token)->getJson('/api/users/me')->assertOk();
        $this->withToken($token)->postJson('/api/logout')->assertNoContent();
    }
}

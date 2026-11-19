<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntermediateTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_authenticated_endpoint_and_logout(): void
    {
        User::factory()->create(['email' => 'teste@example.test', 'password' => 'EntregaDAD2026!']);
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'teste@example.test', 'password' => 'errada'])->assertUnprocessable();
        $token = $this->postJson('/api/auth/login', ['email' => 'teste@example.test', 'password' => 'EntregaDAD2026!'])->assertOk()->json('token');
        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('email', 'teste@example.test')->assertJsonMissingPath('password');
        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_later_stage_endpoints_are_absent(): void
    {
        foreach (['/api/games', '/api/wallet', '/api/ranking', '/api/tournaments', '/api/admin/overview'] as $path) {
            $this->getJson($path)->assertNotFound();
        }
    }
}

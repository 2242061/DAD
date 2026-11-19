<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_console_can_create_an_admin_with_a_hashed_password(): void
    {
        $this->artisan('app:create-admin', ['email' => 'admin@example.test', '--name' => 'Admin Clube'])
            ->expectsQuestion('Palavra-passe (mínimo 8 caracteres, com letras e números)', 'Admin2026!')
            ->expectsQuestion('Repete a palavra-passe', 'Admin2026!')
            ->assertExitCode(0);

        $admin = User::sole();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue(Hash::check('Admin2026!', $admin->password));
    }

    public function test_the_command_does_not_promote_an_existing_player(): void
    {
        $player = User::factory()->create(['email' => 'player@example.test']);
        $this->artisan('app:create-admin', ['email' => $player->email, '--name' => 'Admin Clube'])
            ->expectsQuestion('Palavra-passe (mínimo 8 caracteres, com letras e números)', 'Admin2026!')
            ->expectsQuestion('Repete a palavra-passe', 'Admin2026!')
            ->assertExitCode(1);

        $this->assertSame(UserRole::Player, $player->fresh()->role);
    }
}

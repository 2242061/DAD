<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_cannot_access_administration(): void
    {
        $this->getJson('/api/admin/overview')->assertUnauthorized();
    }

    public function test_a_player_cannot_access_administration_even_by_calling_the_api(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/admin/overview')->assertForbidden();
    }

    public function test_an_admin_can_view_real_counts_and_paginated_users_without_passwords(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->count(11)->create();

        $this->actingAs($admin, 'web')->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('stats.total', 12)
            ->assertJsonPath('stats.players', 11)
            ->assertJsonPath('stats.admins', 1)
            ->assertJsonCount(10, 'users')
            ->assertJsonMissingPath('users.0.password')
            ->assertJsonMissingPath('users.0.remember_token')
            ->assertJsonPath('pagination.last_page', 2);

        $this->getJson('/api/admin/overview?page=2')->assertOk()->assertJsonCount(2, 'users');
    }
}

<?php

namespace Tests\Feature;

use App\Models\OnlineGame;
use App\Models\User;
use App\Services\CheckersEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://127.0.0.1:5173');
        $this->freezeTime();
    }

    private function game(User $user, string $color, ?string $winner, string $phase = 'finished'): OnlineGame
    {
        $state = (new CheckersEngine)->initial();
        $state['status'] = $phase === 'finished' ? 'finished' : 'active';
        $state['winner'] = $winner;
        $state['reason'] = $phase === 'finished' ? ($winner === null ? 'threefold' : 'resignation') : null;

        return OnlineGame::factory()->create([
            $color === 'white' ? 'white_user_id' : 'black_user_id' => $user->id,
            'black_key' => $phase === 'waiting' ? null : hash('sha256', 'opponent'), 'phase' => $phase, 'state' => $state,
            'started_at' => now()->subMinutes(3), 'turn_deadline_at' => $phase === 'active' ? now()->addMinute() : null,
            'finished_at' => $phase === 'finished' ? now() : null,
        ]);
    }

    public function test_profile_summarizes_both_colors_and_excludes_waiting_rooms_and_other_players(): void
    {
        $user = User::factory()->create();
        $this->game($user, 'white', 'white');
        $this->game($user, 'black', 'black');
        $this->game($user, 'white', null);
        $this->game($user, 'black', 'white');
        $this->game($user, 'white', null, 'active');
        $this->game($user, 'white', null, 'waiting');
        $this->game(User::factory()->create(), 'white', 'white');
        $this->actingAs($user, 'web')->getJson('/api/account/games')->assertOk()->assertExactJsonStructure(['data', 'stats', 'meta', 'ranking'])
            ->assertJsonPath('stats', ['games' => 5, 'finished' => 4, 'wins' => 2, 'draws' => 1, 'losses' => 1, 'active' => 1])
            ->assertJsonPath('meta.total', 5)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_history_is_private_paginated_and_contains_duration_without_secrets(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 12; $i++) {
            $this->game($user, 'white', 'white');
        }
        $this->getJson('/api/account/games')->assertUnauthorized();
        $response = $this->actingAs($user, 'web')->getJson('/api/account/games')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.last_page', 2);
        $record = $response->json('data.0');
        $this->assertSame(180, $record['duration_seconds']);
        $this->assertArrayNotHasKey('white_key', $record);
        $this->assertArrayNotHasKey('black_key', $record);
        $this->getJson('/api/account/games?page=2')->assertJsonCount(2, 'data');
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/account/games')->assertJsonPath('stats.games', 0)->assertJsonCount(0, 'data');
    }

    public function test_refreshing_history_does_not_double_count_finished_results(): void
    {
        $user = User::factory()->create();
        $this->game($user, 'black', 'white');
        $this->actingAs($user, 'web');
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/account/games')->assertJsonPath('stats.losses', 1)->assertJsonPath('stats.games', 1);
        }
    }

    public function test_reusing_own_waiting_room_after_login_attaches_account_before_game_starts(): void
    {
        $this->withSession(['online_player' => 'creator']);
        $game = $this->postJson('/api/games')->assertCreated()->json('data');
        $this->assertNull(OnlineGame::sole()->white_user_id);
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->postJson('/api/games')->assertCreated()->assertJsonPath('data.id', $game['id']);
        $this->assertSame($user->id, OnlineGame::sole()->white_user_id);
    }

    public function test_same_registered_account_cannot_join_its_own_room_from_a_different_session(): void
    {
        $user = User::factory()->create();
        $game = $this->actingAs($user, 'web')->withSession(['online_player' => 'first'])->postJson('/api/games')->assertCreated()->json('data');
        $this->withSession(['online_player' => 'second'])->postJson('/api/games/'.$game['code'].'/join')->assertConflict();
        $this->assertNull(OnlineGame::sole()->black_key);
    }
}

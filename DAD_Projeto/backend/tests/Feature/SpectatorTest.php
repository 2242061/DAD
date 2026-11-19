<?php

namespace Tests\Feature;

use App\Models\OnlineGame;
use App\Models\User;
use App\Services\CheckersEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpectatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_list_public_matches_but_cannot_view_private_or_unstarted_rooms(): void
    {
        $ranked = OnlineGame::factory()->create(['mode' => 'ranked', 'phase' => 'active', 'started_at' => now()]);
        $tournament = OnlineGame::factory()->create(['mode' => 'tournament', 'phase' => 'active', 'started_at' => now()]);
        $private = OnlineGame::factory()->create(['phase' => 'active', 'started_at' => now()]);
        $waiting = OnlineGame::factory()->create(['mode' => 'ranked']);

        $this->getJson('/api/spectate')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonFragment(['code' => $ranked->code])->assertJsonFragment(['code' => $tournament->code]);
        foreach ([$private, $waiting] as $game) {
            $this->getJson('/api/spectate/'.$game->code)->assertNotFound();
            $this->getJson('/api/spectate/'.$game->code.'/ticket')->assertNotFound();
        }
        $this->getJson('/api/spectate/'.$tournament->code)->assertOk()->assertJsonPath('data.mode', 'tournament');
    }

    public function test_spectator_can_read_updated_board_and_result_but_cannot_play(): void
    {
        config(['services.realtime.secret' => str_repeat('a', 64)]);
        Http::fake(['*' => Http::response([], 202)]);
        $white = User::factory()->create();
        $black = User::factory()->create();
        $game = OnlineGame::factory()->create(['mode' => 'ranked', 'phase' => 'active', 'started_at' => now(),
            'turn_deadline_at' => now()->addMinute(), 'white_user_id' => $white->id, 'black_user_id' => $black->id]);
        $public = '/api/spectate/'.$game->code;
        $play = '/api/games/'.$game->code;

        $this->getJson($public)->assertOk()->assertJsonPath('data.players.white', $white->name)
            ->assertJsonPath('data.legal_moves', [])->assertJsonMissingPath('data.my_color')
            ->assertJsonMissingPath('data.white_user_id')->assertJsonMissingPath('data.white_key')
            ->assertJsonMissingPath('data.wallet_reward');
        $ticket = $this->getJson($public.'/ticket')->assertOk()->json('ticket');
        $claims = json_decode(base64_decode(strtr(explode('.', $ticket)[0], '-_', '+/')), true);
        $this->assertSame('spectator', $claims['color']);
        $this->assertSame($game->id, $claims['game']);

        $this->withHeader('Origin', 'http://127.0.0.1:5173');
        $this->patchJson($play, ['action' => 'resign', 'revision' => 0])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->patchJson($play, ['action' => 'resign', 'revision' => 0])->assertForbidden();

        $move = app(CheckersEngine::class)->legalMoves($game->state)[0]['path'];
        $this->actingAs($white, 'web')->patchJson($play, ['action' => 'move', 'path' => $move, 'revision' => 0])->assertOk();
        $this->getJson($public)->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.history.0.path', $move);
        $this->patchJson($play, ['action' => 'resign', 'revision' => 1])->assertOk();
        $this->getJson($public)->assertOk()->assertJsonPath('data.winner', 'black');
        $this->getJson('/api/spectate')->assertJsonCount(0, 'data');
    }
}

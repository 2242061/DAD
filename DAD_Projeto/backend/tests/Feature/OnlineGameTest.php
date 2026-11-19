<?php

namespace Tests\Feature;

use App\Models\OnlineGame;
use App\Models\User;
use App\Services\CheckersEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OnlineGameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://127.0.0.1:5173');
        config(['services.realtime.secret' => str_repeat('a', 64), 'services.realtime.url' => 'http://127.0.0.1:3001']);
        Http::fake(['*' => Http::response([], 202)]);
    }

    private function asPlayer(string $player): static
    {
        return $this->withSession(['online_player' => $player]);
    }

    private function room(bool $joined = true): array
    {
        $game = $this->asPlayer('creator')->postJson('/api/games')->assertCreated()->json('data');
        if ($joined) {
            $game = $this->asPlayer('opponent')->postJson('/api/games/'.$game['code'].'/join')->assertOk()->json('data');
        }

        return $game;
    }

    private function move(array $game, array $path, ?int $revision = null): TestResponse
    {
        return $this->patchJson('/api/games/'.$game['code'], ['revision' => $revision ?? $game['revision'], 'action' => 'move', 'path' => $path]);
    }

    public function test_guest_creates_private_waiting_room_and_repeated_creation_is_idempotent(): void
    {
        $game = $this->room(false);
        $this->assertSame('white', $game['my_color']);
        $this->assertSame('waiting', $game['phase']);
        $this->assertSame([], $game['legal_moves']);
        foreach (['white_key', 'black_key', 'repetitions', 'white_user_id'] as $private) {
            $this->assertArrayNotHasKey($private, $game);
        }
        $this->postJson('/api/games')->assertCreated()->assertJsonPath('data.id', $game['id']);
        $this->assertDatabaseCount('online_games', 1);
    }

    public function test_second_player_joins_as_black_and_third_player_is_rejected(): void
    {
        $game = $this->room();
        $this->assertSame('black', $game['my_color']);
        $this->assertSame('active', $game['phase']);
        $this->assertSame(1, $game['revision']);
        $this->asPlayer('creator')->postJson('/api/games/'.$game['code'].'/join')->assertJsonPath('data.my_color', 'white');
        $this->asPlayer('intruder')->postJson('/api/games/'.$game['code'].'/join')->assertConflict();
        $this->getJson('/api/games/'.$game['code'])->assertForbidden();
        $this->getJson('/api/games/'.$game['code'].'/ticket')->assertForbidden();
        $this->move($game, [9, 13])->assertForbidden();
        $this->assertDatabaseCount('online_games', 1);
    }

    public function test_same_session_cannot_occupy_both_colors_and_waiting_room_cannot_move(): void
    {
        $game = $this->room(false);
        $this->postJson('/api/games/'.$game['code'].'/join')->assertOk()->assertJsonPath('data.phase', 'waiting')->assertJsonPath('data.my_color', 'white');
        $this->move($game, [9, 13])->assertConflict();
    }

    public function test_each_player_can_only_play_their_own_turn_and_color(): void
    {
        $game = $this->room();
        $this->move($game, [9, 13])->assertForbidden();
        $game = $this->asPlayer('creator')->move($game, [9, 13])->assertOk()->json('data');
        $this->move($game, [22, 18])->assertForbidden();
        $this->asPlayer('opponent')->move($game, [10, 14])->assertUnprocessable();
        $this->move($game, [22, 18])->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.turn', 'white');
        $this->asPlayer('creator')->getJson('/api/games/'.$game['code'])->assertOk()->assertJsonPath('data.history.1.notation', '22-18');
        Http::assertSentCount(3);
    }

    public function test_stale_duplicate_moves_are_rejected_without_overwriting_history(): void
    {
        $game = $this->room();
        $this->asPlayer('creator')->move($game, [9, 13])->assertOk();
        $this->move($game, [10, 14])->assertConflict();
        $this->assertCount(1, OnlineGame::sole()->state['history']);
        $this->assertSame(2, OnlineGame::sole()->revision);
    }

    public function test_client_cannot_forge_board_or_choose_color(): void
    {
        $this->postJson('/api/games', ['color' => 'black'])->assertUnprocessable();
        $game = $this->room();
        $this->asPlayer('creator')->patchJson('/api/games/'.$game['code'], [
            'revision' => 1, 'action' => 'move', 'path' => [9, 13], 'board' => ['W'],
        ])->assertUnprocessable();
        $this->assertSame(1, OnlineGame::sole()->revision);
    }

    public function test_player_can_resign_even_when_it_is_the_opponents_turn(): void
    {
        $game = $this->room();
        $this->asPlayer('opponent')->patchJson('/api/games/'.$game['code'], ['revision' => 1, 'action' => 'resign'])
            ->assertOk()->assertJsonPath('data.winner', 'white')->assertJsonPath('data.phase', 'finished');
        $this->asPlayer('creator')->move($game, [9, 13], 2)->assertConflict();
        $this->asPlayer('third')->postJson('/api/games/'.$game['code'].'/join')->assertConflict();
    }

    public function test_waiting_room_can_be_closed_without_awarding_a_win(): void
    {
        $game = $this->room(false);
        $this->patchJson('/api/games/'.$game['code'], ['revision' => 0, 'action' => 'resign'])->assertOk()
            ->assertJsonPath('data.winner', null)->assertJsonPath('data.reason', 'cancelled');
    }

    public function test_notification_failure_does_not_lose_a_committed_move(): void
    {
        $game = $this->room();
        Http::fake(['*' => Http::response([], 503)]);
        $this->asPlayer('creator')->move($game, [9, 13])->assertOk()->assertJsonPath('data.revision', 2);
        $this->asPlayer('opponent')->getJson('/api/games/'.$game['code'])->assertOk()->assertJsonPath('data.history.0.notation', '9-13');
    }

    public function test_ticket_is_signed_short_lived_and_contains_no_session_or_user_data(): void
    {
        $game = $this->room();
        $ticket = $this->getJson('/api/games/'.$game['code'].'/ticket')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('ticket');
        [$payload, $signature] = explode('.', $ticket);
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertSame(['aud', 'game', 'color', 'exp'], array_keys($data));
        $this->assertSame('black', $data['color']);
        $this->assertSame($game['id'], $data['game']);
        $this->assertSame(now()->timestamp + 90, $data['exp']);
        $this->assertSame(rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, str_repeat('a', 64), true)), '+/', '-_'), '='), $signature);
    }

    public function test_missing_room_and_requests_without_csrf_or_session_are_rejected(): void
    {
        $this->getJson('/api/games/AAAAAAAAAA')->assertNotFound();
        $this->withoutHeader('Origin')->postJson('/api/games')->assertForbidden();
        $this->withHeader('Origin', 'http://127.0.0.1:5173');
        $this->app->instance('env', 'local');
        $this->postJson('/api/games')->assertStatus(419);
    }

    public function test_registered_player_is_recorded_and_blocked_account_cannot_join(): void
    {
        $user = User::factory()->create();
        $game = $this->actingAs($user)->withSession(['auth_version' => 0, 'online_player' => 'creator'])->postJson('/api/games')->assertCreated()->json('data');
        $this->assertSame($user->id, OnlineGame::sole()->white_user_id);
        $user->forceFill(['blocked_at' => now()])->save();
        $this->getJson('/api/games/'.$game['code'])->assertForbidden();
    }

    public function test_reading_a_game_does_not_consume_the_room_creation_limit(): void
    {
        $game = $this->room();
        $this->asPlayer('creator');
        for ($i = 0; $i < 12; $i++) {
            $this->getJson('/api/games/'.$game['code'])->assertOk();
        }
        $this->postJson('/api/games')->assertCreated();
    }

    public function test_room_creation_is_rate_limited_independently(): void
    {
        $this->asPlayer('creator');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/games')->assertCreated();
        }
        $this->postJson('/api/games')->assertTooManyRequests();
    }

    public function test_terminal_engine_result_is_persisted_for_both_players(): void
    {
        $game = $this->room();
        $board = array_fill(0, 32, null);
        $board[9] = 'w';
        $board[13] = 'b';
        $state = (new CheckersEngine)->position($board);
        $state['revision'] = 1;
        OnlineGame::sole()->update(['state' => $state]);
        $this->asPlayer('creator')->move($game, [10, 19])->assertOk()->assertJsonPath('data.phase', 'finished')->assertJsonPath('data.winner', 'white');
        $this->asPlayer('opponent')->getJson('/api/games/'.$game['code'])->assertJsonPath('data.reason', 'capture');
    }
}

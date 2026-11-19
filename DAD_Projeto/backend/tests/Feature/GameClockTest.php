<?php

namespace Tests\Feature;

use App\Models\OnlineGame;
use App\Services\CheckersEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GameClockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->travelTo(now()->startOfSecond());
        $this->withHeader('Origin', 'http://127.0.0.1:5173')->withSession(['online_player' => 'white']);
        Http::fake(['*' => Http::response([], 202)]);
        config(['services.realtime.url' => 'http://127.0.0.1:3001']);
    }

    private function game(): OnlineGame
    {
        return OnlineGame::factory()->create([
            'white_key' => hash('sha256', 'white'), 'black_key' => hash('sha256', 'black'),
            'phase' => 'active', 'started_at' => now(), 'turn_deadline_at' => now()->addMinute(),
        ]);
    }

    public function test_waiting_room_clock_starts_only_when_opponent_joins(): void
    {
        $game = $this->postJson('/api/games')->assertCreated()->json('data');
        $this->assertNull($game['clock']['started_at']);
        $this->travel(90)->seconds();
        $this->withSession(['online_player' => 'black'])->postJson('/api/games/'.$game['code'].'/join')
            ->assertOk()->assertJsonPath('data.clock.started_at', now()->getTimestampMs())
            ->assertJsonPath('data.clock.deadline_at', now()->addMinute()->getTimestampMs());
    }

    public function test_casual_timeout_finishes_once_and_awards_the_opponent_the_win(): void
    {
        $game = $this->game();
        $this->travel(60)->seconds();
        $this->getJson('/api/games/'.$game->code)->assertOk()->assertJsonPath('data.reason', 'timeout')
            ->assertJsonPath('data.revision', 1)->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.winner', 'black');
        $this->getJson('/api/games/'.$game->code)->assertJsonPath('data.revision', 1);
        Http::assertSentCount(1);
    }

    public function test_expired_move_is_rejected_and_cannot_reset_clock(): void
    {
        $game = $this->game();
        $this->travel(61)->seconds();
        $this->patchJson('/api/games/'.$game->code, ['action' => 'move', 'revision' => 0, 'path' => [9, 13]])->assertConflict();
        $this->patchJson('/api/games/'.$game->code, ['action' => 'move', 'revision' => 1, 'path' => [9, 13]])->assertConflict();
        $this->assertSame('w', $game->fresh()->state['board'][8]);
        $this->assertNull($game->fresh()->turn_deadline_at);
    }

    public function test_one_absent_player_loses_even_when_it_is_not_their_turn(): void
    {
        $game = $this->game();
        $game->update(['white_seen_at' => now(), 'black_seen_at' => now()]);
        $this->travel(20)->seconds();
        $this->getJson('/api/games/'.$game->code)->assertJsonPath('data.status', 'active');
        $this->travel(11)->seconds();
        $this->getJson('/api/games/'.$game->code)->assertJsonPath('data.reason', 'abandonment')
            ->assertJsonPath('data.winner', 'white')->assertJsonPath('data.revision', 1);
    }

    public function test_reconnection_before_grace_expiry_keeps_the_game_active(): void
    {
        $game = $this->game();
        $game->update(['white_seen_at' => now(), 'black_seen_at' => now()]);
        $this->travel(20)->seconds();
        $this->withSession(['online_player' => 'black'])->getJson('/api/games/'.$game->code)->assertJsonPath('data.status', 'active');
        $this->withSession(['online_player' => 'white'])->getJson('/api/games/'.$game->code)->assertJsonPath('data.status', 'active');
        $this->travel(11)->seconds();
        $this->getJson('/api/games/'.$game->code)->assertJsonPath('data.status', 'active');
    }

    public function test_legal_move_gives_next_player_full_turn_and_total_timer_keeps_start(): void
    {
        $game = $this->game();
        $start = $game->started_at->getTimestampMs();
        $this->travel(25)->seconds();
        $this->patchJson('/api/games/'.$game->code, ['action' => 'move', 'revision' => 0, 'path' => [9, 13]])->assertOk()
            ->assertJsonPath('data.clock.started_at', $start)->assertJsonPath('data.clock.deadline_at', now()->addMinute()->getTimestampMs());
    }

    public function test_only_the_other_player_can_accept_a_draw_without_resetting_the_turn_clock(): void
    {
        $game = $this->game();
        $url = '/api/games/'.$game->code;
        $this->patchJson($url, ['action' => 'offer_draw', 'revision' => 0])->assertConflict();
        $deadline = $this->patchJson($url, ['action' => 'move', 'revision' => 0, 'path' => [9, 13]])
            ->assertOk()->json('data.clock.deadline_at');
        $this->travel(10)->seconds();
        $this->patchJson($url, ['action' => 'offer_draw', 'revision' => 1])->assertOk()
            ->assertJsonPath('data.draw_offer', 'white')->assertJsonPath('data.clock.deadline_at', $deadline);
        $this->patchJson($url, ['action' => 'accept_draw', 'revision' => 2])->assertConflict();
        $this->withSession(['online_player' => 'black'])->patchJson($url, ['action' => 'accept_draw', 'revision' => 2])
            ->assertOk()->assertJsonPath('data.winner', null)->assertJsonPath('data.reason', 'agreement')
            ->assertJsonPath('data.clock.deadline_at', null);
    }

    public function test_technical_draw_distinguishes_a_drawn_ending_from_a_forced_capture(): void
    {
        $game = $this->game();
        $url = '/api/games/'.$game->code;
        $board = array_fill(0, 32, null);
        $board[0] = 'W';
        $board[9] = 'B';
        $game->update(['state' => (new CheckersEngine)->position($board)]);
        $this->patchJson($url, ['action' => 'claim_draw', 'revision' => 0])->assertUnprocessable();
        $board[9] = null;
        $board[1] = 'B';
        $game->update(['state' => (new CheckersEngine)->position($board)]);
        $this->patchJson($url, ['action' => 'claim_draw', 'revision' => 0])->assertOk()
            ->assertJsonPath('data.reason', 'dead_position')->assertJsonPath('data.winner', null);
    }

    public function test_technical_draw_can_be_proved_with_five_pawns(): void
    {
        $game = $this->game();
        $board = array_fill(0, 32, null);
        foreach ([4 => 'b', 15 => 'w', 20 => 'b', 25 => 'w', 26 => 'b'] as $square => $piece) {
            $board[$square - 1] = $piece;
        }
        $game->update(['state' => (new CheckersEngine)->position($board, 'black')]);
        $this->withSession(['online_player' => 'black'])->patchJson('/api/games/'.$game->code, ['action' => 'claim_draw', 'revision' => 0])
            ->assertOk()->assertJsonPath('data.reason', 'dead_position')->assertJsonPath('data.winner', null);
    }

    public function test_finished_match_freezes_total_time_and_stops_turn_clock(): void
    {
        $game = $this->game();
        $this->travel(24)->seconds();
        $end = now()->getTimestampMs();
        $this->patchJson('/api/games/'.$game->code, ['action' => 'resign', 'revision' => 0])->assertOk()
            ->assertJsonPath('data.clock.finished_at', $end)->assertJsonPath('data.clock.deadline_at', null);
        $this->travel(100)->seconds();
        $this->getJson('/api/games/'.$game->code)->assertJsonPath('data.clock.finished_at', $end)->assertJsonPath('data.revision', 1);
    }

    public function test_when_both_are_absent_the_turn_deadline_still_decides_the_result(): void
    {
        $game = $this->game();
        $game->update(['white_seen_at' => now(), 'black_seen_at' => now()]);
        $deadline = $game->turn_deadline_at->getTimestampMs();
        $this->travel(185)->seconds();
        $this->getJson('/api/games/'.$game->code)->assertOk()->assertJsonPath('data.reason', 'timeout')
            ->assertJsonPath('data.winner', 'black')->assertJsonPath('data.clock.finished_at', $deadline);
    }

    public function test_local_practice_awards_timeout_win_and_rejects_old_move(): void
    {
        $game = $this->postJson('/api/practice')->assertCreated()->json('data');
        $this->travel(60)->seconds();
        $this->patchJson('/api/practice', ['id' => $game['id'], 'revision' => 0, 'action' => 'move', 'path' => [9, 13]])->assertConflict();
        $this->getJson('/api/practice')->assertOk()->assertJsonPath('data.winner', 'black')->assertJsonPath('data.reason', 'timeout');
    }
}

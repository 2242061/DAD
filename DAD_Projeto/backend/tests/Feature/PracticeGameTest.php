<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PracticeGameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://127.0.0.1:5173');
    }

    public function test_guest_can_create_move_and_reload_a_private_local_game(): void
    {
        $this->getJson('/api/practice')->assertOk()->assertJsonPath('data', null);
        $game = $this->postJson('/api/practice')->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertCount(7, $game['legal_moves']);
        $this->assertArrayNotHasKey('repetitions', $game);
        $this->patchJson('/api/practice', ['id' => $game['id'], 'revision' => 0, 'action' => 'move', 'path' => [9, 13]])
            ->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.turn', 'black')
            ->assertJsonPath('data.history.0.notation', '9-13');
        $this->getJson('/api/practice')->assertOk()->assertJsonPath('data.board.12', 'w');
    }

    public function test_guest_can_train_against_the_bot_in_a_separate_session_game(): void
    {
        $local = $this->postJson('/api/practice')->assertCreated()->json('data.id');
        $url = '/api/practice?mode=bot';
        $this->getJson($url)->assertJsonPath('data', null);
        $game = $this->postJson($url)->assertCreated()->assertJsonPath('data.clock.timeout_rule', 'loss')->json('data');
        $this->patchJson($url, ['id' => $game['id'], 'revision' => 0, 'action' => 'move', 'path' => [9, 13]])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.turn', 'white')
            ->assertJsonPath('data.history.1.color', 'black');
        $this->getJson('/api/practice')->assertJsonPath('data.id', $local)->assertJsonPath('data.revision', 0);
        $this->travel(61)->seconds();
        $this->getJson($url)->assertJsonPath('data.winner', 'black')->assertJsonPath('data.reason', 'timeout');
        $this->assertDatabaseCount('online_games', 0);
    }

    public function test_illegal_incomplete_or_forged_moves_do_not_mutate_the_game(): void
    {
        $game = $this->postJson('/api/practice')->json('data');
        $base = ['id' => $game['id'], 'revision' => 0, 'action' => 'move'];
        foreach ([[1, 32], [22, 18], [9, 33], [9], [9, 13, 17]] as $path) {
            $this->patchJson('/api/practice', [...$base, 'path' => $path])->assertUnprocessable();
        }
        $this->patchJson('/api/practice', [...$base, 'path' => [9, 13], 'board' => ['W']])->assertUnprocessable();
        $this->getJson('/api/practice')->assertJsonPath('data.revision', 0)->assertJsonPath('data.history', []);
    }

    public function test_stale_revision_and_a_previous_game_id_are_rejected(): void
    {
        $game = $this->postJson('/api/practice')->json('data');
        $body = ['id' => $game['id'], 'revision' => 0, 'action' => 'move', 'path' => [9, 13]];
        $this->patchJson('/api/practice', $body)->assertOk();
        $this->patchJson('/api/practice', $body)->assertConflict();
        $this->postJson('/api/practice')->assertCreated();
        $this->patchJson('/api/practice', $body)->assertConflict();
    }

    public function test_another_session_cannot_read_or_move_the_game(): void
    {
        $game = $this->postJson('/api/practice')->json('data');
        $this->app['session']->flush();
        $this->getJson('/api/practice')->assertJsonPath('data', null);
        $this->patchJson('/api/practice', ['id' => $game['id'], 'revision' => 0, 'action' => 'move', 'path' => [9, 13]])->assertNotFound();
    }

    public function test_practice_requires_a_frontend_session_and_csrf_on_writes(): void
    {
        $this->withoutHeader('Origin')->postJson('/api/practice')->assertForbidden();
        $this->withHeader('Origin', 'http://127.0.0.1:5173');
        $this->app->instance('env', 'local');
        $this->postJson('/api/practice')->assertStatus(419);
    }

    public function test_resignation_finishes_game_and_restart_has_a_new_id(): void
    {
        $game = $this->postJson('/api/practice')->json('data');
        $this->patchJson('/api/practice', ['id' => $game['id'], 'revision' => 0, 'action' => 'resign'])
            ->assertOk()->assertJsonPath('data.winner', 'black')->assertJsonPath('data.reason', 'resignation');
        $this->patchJson('/api/practice', ['id' => $game['id'], 'revision' => 1, 'action' => 'move', 'path' => [9, 13]])->assertUnprocessable();
        $next = $this->postJson('/api/practice')->assertCreated()->json('data');
        $this->assertNotSame($game['id'], $next['id']);
        $this->assertSame(0, $next['revision']);
    }
}

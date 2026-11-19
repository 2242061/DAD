<?php

namespace Tests\Feature;

use App\Models\OnlineGame;
use App\Models\RankEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CompetitionWallet;
use App\Services\CompetitiveRank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CompetitiveRankTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://127.0.0.1:5173');
        Http::fake(['*' => Http::response([], 202)]);
        config(['services.realtime.url' => 'http://127.0.0.1:3001', 'services.realtime.secret' => str_repeat('a', 64)]);
    }

    private function actor(?User $user, string $key): static
    {
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->withSession(['online_player' => $key, 'auth_version' => 0]);
        if ($user) {
            $this->actingAs($user->fresh(), 'web');
        }

        return $this;
    }

    private function match(User $white, User $black, string $mode = 'ranked'): array
    {
        $game = $this->actor($white, 'white')->postJson('/api/games', ['mode' => $mode])->assertCreated()->json('data');
        $this->assertSame($mode, $game['mode']);

        if ($mode === 'ranked') {
            foreach ([$white, $black] as $player) {
                Wallet::firstOrCreate(['user_id' => $player->id], ['balance' => 200]);
            }
        }

        return $this->actor($black, 'black')->postJson('/api/games/'.$game['code'].'/join', ['entry_fee' => $game['entry_fee']])->assertOk()->json('data');
    }

    public function test_public_lobby_hides_private_and_own_rooms_and_ranked_join_debits_both_wallets_and_pays_the_winner(): void
    {
        $white = User::factory()->create();
        $black = User::factory()->create();
        $this->actor($white, 'white')->postJson('/api/games', ['mode' => 'ranked'])->assertCreated();
        $game = $this->postJson('/api/games', ['mode' => 'ranked', 'discoverable' => true])
            ->assertCreated()->assertJsonPath('data.discoverable', true)->json('data');
        $this->getJson('/api/games/open')->assertJsonCount(0, 'data');

        Wallet::create(['user_id' => $white->id, 'balance' => 200]);
        $this->assertSame(CompetitionWallet::RANKED_FEE, $game['entry_fee']);
        $this->assertDatabaseCount('wallet_entries', 0);

        $this->actor($black, 'black')->getJson('/api/games/open')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $game['code'])->assertJsonPath('data.0.host', $white->name);
        $url = '/api/games/'.$game['code'].'/join';
        $this->postJson($url, ['entry_fee' => 1])->assertConflict();
        $this->postJson($url, ['entry_fee' => $game['entry_fee']])->assertConflict();
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertDatabaseHas('online_games', ['id' => $game['id'], 'phase' => 'waiting']);
        $this->assertSame(200, Wallet::where('user_id', $white->id)->value('balance'));
        Wallet::create(['user_id' => $black->id, 'balance' => 200]);
        $this->postJson($url, ['entry_fee' => $game['entry_fee']])->assertOk()->assertJsonPath('data.phase', 'active');
        $this->postJson($url, ['entry_fee' => $game['entry_fee']])->assertOk();
        $this->assertDatabaseCount('wallet_entries', 2);
        $this->assertSame(150, Wallet::where('user_id', $white->id)->value('balance'));
        $this->assertSame(150, Wallet::where('user_id', $black->id)->value('balance'));
        $this->actor(null, 'guest')->getJson('/api/games/open')->assertJsonCount(0, 'data');
        $this->finish(OnlineGame::findOrFail($game['id']), 'white');
        $this->assertDatabaseCount('wallet_entries', 3);
        $this->assertSame(250, Wallet::where('user_id', $white->id)->value('balance'));
        $this->assertSame(150, Wallet::where('user_id', $black->id)->value('balance'));
        $this->assertDatabaseCount('rank_entries', 2);
    }

    public function test_visitors_can_find_and_join_an_open_casual_room(): void
    {
        $room = $this->actor(null, 'host')->postJson('/api/games', ['discoverable' => true])->assertCreated()->json('data');
        $this->actor(null, 'visitor')->getJson('/api/games/open')->assertOk()->assertJsonPath('data.0.code', $room['code'])
            ->assertJsonPath('data.0.host', 'Visitante');
        $this->postJson('/api/games/'.$room['code'].'/join')->assertOk()->assertJsonPath('data.my_color', 'black');
        $this->actor(null, 'third')->postJson('/api/games/'.$room['code'].'/join')->assertConflict();
    }

    private function fixture(User $white, User $black, string $mode = 'ranked'): OnlineGame
    {
        return OnlineGame::factory()->create(['mode' => $mode, 'white_user_id' => $white->id, 'black_user_id' => $black->id,
            'white_key' => hash('sha256', 'white'), 'black_key' => hash('sha256', 'black'), 'phase' => 'active']);
    }

    private function finish(OnlineGame $game, ?string $winner): OnlineGame
    {
        $state = $game->state;
        $state['status'] = 'finished';
        $state['winner'] = $winner;
        $state['reason'] = $winner === null ? 'threefold' : 'resignation';
        $state['revision']++;

        return app(CompetitiveRank::class)->commit($game, ['state' => json_encode($state), 'phase' => 'finished', 'revision' => $state['revision'], 'finished_at' => now()]);
    }

    public function test_guests_can_play_casual_but_cannot_create_or_join_competitive_rooms(): void
    {
        $this->actor(null, 'guest')->postJson('/api/games', ['mode' => 'casual'])->assertCreated()->assertJsonPath('data.mode', 'casual');
        $this->postJson('/api/games', ['mode' => 'ranked'])->assertUnauthorized();
        $host = User::factory()->create();
        $game = $this->actor($host, 'host')->postJson('/api/games', ['mode' => 'ranked'])->assertCreated()->json('data');
        $this->actor(null, 'guest')->getJson('/api/games/'.$game['code'].'/invitation')->assertOk()->assertExactJson(['data' => ['code' => $game['code'], 'mode' => 'ranked', 'phase' => 'waiting', 'entry_fee' => CompetitionWallet::RANKED_FEE]]);
        $this->postJson('/api/games/'.$game['code'].'/join')->assertUnauthorized();
        $this->getJson('/api/games/'.$game['code'])->assertUnauthorized();
        $this->getJson('/api/games/'.$game['code'].'/ticket')->assertUnauthorized();
        $this->assertNull(OnlineGame::where('code', $game['code'])->first()->black_user_id);
    }

    public function test_competitive_identity_is_bound_to_account_and_survives_a_new_session(): void
    {
        $white = User::factory()->create();
        $black = User::factory()->create();
        $game = $this->match($white, $black);
        $this->actor($white, 'new-session')->getJson('/api/games/'.$game['code'])->assertOk()->assertJsonPath('data.my_color', 'white');
        $this->actor(User::factory()->create(), 'white')->getJson('/api/games/'.$game['code'])->assertForbidden();
        $this->actor(null, 'white')->patchJson('/api/games/'.$game['code'], ['action' => 'resign', 'revision' => 1])->assertUnauthorized();
    }

    public function test_same_account_cannot_occupy_both_colors_and_modes_have_separate_waiting_rooms(): void
    {
        $user = User::factory()->create();
        $casual = $this->actor($user, 'white')->postJson('/api/games', ['mode' => 'casual'])->assertCreated()->json('data');
        $ranked = $this->postJson('/api/games', ['mode' => 'ranked'])->assertCreated()->json('data');
        $this->assertNotSame($casual['id'], $ranked['id']);
        $this->actor($user, 'other-session')->postJson('/api/games/'.$ranked['code'].'/join')->assertOk()->assertJsonPath('data.phase', 'waiting')->assertJsonPath('data.my_color', 'white');
        $this->assertNull(OnlineGame::where('code', $ranked['code'])->first()->black_user_id);
    }

    public function test_casual_results_and_local_practice_never_change_rank(): void
    {
        $white = User::factory()->create(['rank_points' => 100]);
        $black = User::factory()->create(['rank_points' => 50]);
        $game = $this->match($white, $black, 'casual');
        $this->patchJson('/api/games/'.$game['code'], ['action' => 'resign', 'revision' => 1])->assertOk()->assertJsonPath('data.rank_change', null);
        $local = $this->postJson('/api/practice')->assertCreated()->json('data');
        $this->patchJson('/api/practice', ['id' => $local['id'], 'action' => 'resign', 'revision' => 0])->assertOk();
        $this->assertSame(100, $white->fresh()->rank_points);
        $this->assertSame(50, $black->fresh()->rank_points);
        $this->assertDatabaseCount('rank_entries', 0);
    }

    public function test_competitive_win_and_loss_are_applied_once_even_after_duplicate_requests(): void
    {
        $white = User::factory()->create();
        $black = User::factory()->create(['rank_points' => 100]);
        $game = $this->match($white, $black);
        $body = ['action' => 'resign', 'revision' => 1];
        $this->patchJson('/api/games/'.$game['code'], $body)->assertOk()->assertJsonPath('data.rank_change.delta', -15)->assertJsonPath('data.rank_change.after', 85);
        $this->patchJson('/api/games/'.$game['code'], $body)->assertConflict();
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/games/'.$game['code'])->assertOk();
        }
        $this->assertSame(30, $white->fresh()->rank_points);
        $this->assertSame(85, $black->fresh()->rank_points);
        $this->assertDatabaseCount('wallet_entries', 3);
        $this->assertDatabaseCount('rank_entries', 2);
        $this->assertNotNull(OnlineGame::where('code', $game['code'])->first()->ranked_scored_at);
        $this->actor($white, 'white')->getJson('/api/account/games')->assertOk()->assertJsonPath('ranking.wins', 1)->assertJsonPath('ranking.points', 30)->assertJsonPath('data.0.rank_delta', 30);
    }

    public function test_black_winner_gets_points_and_loser_cannot_go_below_zero(): void
    {
        $white = User::factory()->create(['rank_points' => 10]);
        $black = User::factory()->create();
        $game = $this->match($white, $black);
        $this->actor($white, 'white')->patchJson('/api/games/'.$game['code'], ['action' => 'resign', 'revision' => 1])->assertOk()->assertJsonPath('data.rank_change.delta', -10)->assertJsonPath('data.rank_change.after', 0);
        $this->assertSame(30, $black->fresh()->rank_points);
    }

    public function test_draw_records_both_outcomes_without_changing_points(): void
    {
        $white = User::factory()->create(['rank_points' => 200]);
        $black = User::factory()->create(['rank_points' => 80]);
        $started = $this->match($white, $black);
        $this->finish(OnlineGame::findOrFail($started['id']), null);
        $this->assertSame(200, Wallet::where('user_id', $white->id)->value('balance'));
        $this->assertSame(200, Wallet::where('user_id', $black->id)->value('balance'));
        $this->assertDatabaseCount('wallet_entries', 4);
        $rank = app(CompetitiveRank::class)->profile($white);
        $this->assertSame(200, $rank['points']);
        $this->assertSame(1, $rank['draws']);
        $this->assertSame(80, $black->fresh()->rank_points);
        $this->assertSame([0, 0], RankEntry::orderBy('id')->pluck('delta')->all());
    }

    public function test_points_promote_and_demote_at_tier_thresholds(): void
    {
        $white = User::factory()->create(['rank_points' => 95]);
        $black = User::factory()->create(['rank_points' => 105]);
        $this->finish($this->fixture($white, $black), 'white');
        $ranks = app(CompetitiveRank::class);
        $this->assertSame('Bronze', $ranks->profile($white)['tier']['name']);
        $this->assertSame('Novato', $ranks->profile($black)['tier']['name']);
        $this->assertCount(10, $ranks->tiers());
        foreach ($ranks->tiers() as $tier) {
            $this->assertSame($tier['name'], $ranks->tier($tier['minimum'])['name']);
            if ($tier['minimum'] > 0) {
                $this->assertSame($tier['level'] - 1, $ranks->tier($tier['minimum'] - 1)['level']);
            }
        }
        $this->assertNull($ranks->tier(9000)['next']);
        $this->assertSame(100, $ranks->tier(9000)['progress']);
    }

    public function test_result_and_point_awards_roll_back_together_on_failure(): void
    {
        $white = User::factory()->create();
        $black = User::factory()->create();
        $game = $this->fixture($white, $black);
        RankEntry::creating(fn () => throw new RuntimeException('Simulated ledger failure'));
        try {
            $this->finish($game, 'white');
            $this->fail('Expected ledger failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated ledger failure', $exception->getMessage());
        } finally {
            RankEntry::flushEventListeners();
        }
        $this->assertSame('active', $game->fresh()->phase);
        $this->assertSame(0, $white->fresh()->rank_points);
        $this->assertSame(0, $black->fresh()->rank_points);
        $this->assertDatabaseCount('rank_entries', 0);
    }

    public function test_stale_settlement_cannot_score_the_same_game_twice(): void
    {
        $white = User::factory()->create();
        $black = User::factory()->create();
        $game = $this->fixture($white, $black);
        $finished = $this->finish($game, 'white');
        $finished->ranked_scored_at = null;
        app(CompetitiveRank::class)->settle($finished);
        $this->assertSame(30, $white->fresh()->rank_points);
        $this->assertDatabaseCount('rank_entries', 2);
    }

    public function test_cancelled_room_is_unscored_and_ranked_timeout_scores_exactly_once(): void
    {
        $user = User::factory()->create();
        $game = $this->actor($user, 'white')->postJson('/api/games', ['mode' => 'ranked'])->assertCreated()->json('data');
        $this->patchJson('/api/games/'.$game['code'], ['action' => 'resign', 'revision' => 0])->assertOk();
        $white = User::factory()->create();
        $black = User::factory()->create();
        $active = $this->fixture($white, $black);
        $active->update(['started_at' => now()->subSeconds(61), 'turn_deadline_at' => now()->subSecond()]);
        $this->assertDatabaseCount('rank_entries', 0);
        $this->actor($white, 'white')->getJson('/api/games/'.$active->code)->assertOk()
            ->assertJsonPath('data.winner', 'black')->assertJsonPath('data.reason', 'timeout');
        $this->getJson('/api/games/'.$active->code)->assertJsonPath('data.winner', 'black');
        $this->assertDatabaseCount('rank_entries', 2);
        $this->assertSame(30, $black->fresh()->rank_points);
    }

    public function test_client_cannot_forge_points_or_change_a_started_game_mode(): void
    {
        $white = User::factory()->create();
        $black = User::factory()->create();
        $game = $this->match($white, $black);
        $this->actor($white, 'white')->patchJson('/api/games/'.$game['code'], ['action' => 'move', 'revision' => 1, 'path' => [9, 13], 'mode' => 'casual', 'points' => 9999])->assertUnprocessable();
        $this->assertSame('ranked', OnlineGame::where('code', $game['code'])->first()->mode);
        $this->assertSame(0, $white->fresh()->rank_points);
    }

    public function test_public_leaderboard_contains_only_competitive_players_and_no_private_fields(): void
    {
        $white = User::factory()->create(['name' => 'Ana']);
        $black = User::factory()->create(['name' => 'Bruno']);
        $this->finish($this->fixture($white, $black), 'white');
        User::factory()->create(['name' => 'Only casual']);
        $this->actor(null, 'guest')->getJson('/api/ranking')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Ana')
            ->assertJsonPath('data.0.points', 30)->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.password')->assertJsonCount(10, 'tiers');
        $white->forceFill(['blocked_at' => now()])->save();
        $this->getJson('/api/ranking')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Bruno');
    }
}

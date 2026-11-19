<?php

namespace Tests\Feature;

use App\Models\OnlineGame;
use App\Models\RankEntry;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CompetitiveRank;
use App\Services\TournamentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TournamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://127.0.0.1:5173');
        Http::fake(['*' => Http::response([], 202)]);
    }

    private function actor(?User $user): static
    {
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->withSession(['auth_version' => 0]);
        if ($user) {
            Wallet::firstOrCreate(['user_id' => $user->id], ['balance' => 200]);
            $this->actingAs($user->fresh(), 'web');
        }

        return $this;
    }

    private function create(User $creator, array $options = []): Tournament
    {
        $id = $this->actor($creator)->postJson('/api/tournaments', array_replace(['name' => 'Liga de testes', 'capacity' => 4, 'min_rank' => 1, 'max_rank' => 10, 'round_minutes' => 2, 'game_minutes' => 1], $options))->assertCreated()->json('data.id');

        return Tournament::findOrFail($id);
    }

    private function filled(int $capacity = 4, array $options = []): array
    {
        $players = User::factory()->count($capacity)->create();
        $t = $this->create($players[0], $options + ['capacity' => $capacity]);
        foreach ($players as $player) {
            $this->actor($player)->postJson('/api/tournaments/'.$t->id.'/join')->assertOk();
        }

        return [$t, $players];
    }

    public function test_guests_cannot_create_enrol_or_view_profile_and_only_admin_can_view_admin_register(): void
    {
        $this->actor(null)->getJson('/api/tournaments')->assertUnauthorized();
        $this->postJson('/api/tournaments', [])->assertUnauthorized();
        $this->getJson('/api/account/tournaments')->assertUnauthorized();
        $player = User::factory()->create();
        $this->actor($player)->getJson('/api/admin/tournaments')->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actor($admin)->getJson('/api/admin/tournaments')->assertOk();
    }

    public function test_configuration_limits_and_rank_interval_are_validated(): void
    {
        $p = User::factory()->create();
        $this->actor($p)->postJson('/api/tournaments', ['name' => 'X', 'capacity' => 2, 'min_rank' => 8, 'max_rank' => 2, 'round_minutes' => 1, 'game_minutes' => 2])->assertUnprocessable()->assertJsonValidationErrors(['name', 'capacity', 'max_rank', 'game_minutes']);
        $this->assertDatabaseCount('tournaments', 0);
        $t = $this->create($p);
        $this->assertSame($p->id, $t->creator_id);
        $this->assertDatabaseCount('tournament_participants', 0);
    }

    public function test_rank_limits_capacity_duplicate_enrolment_and_withdrawal(): void
    {
        $owner = User::factory()->create();
        $t = $this->create($owner, ['min_rank' => 2, 'max_rank' => 3]);
        $this->postJson('/api/tournaments/'.$t->id.'/join')->assertForbidden();
        $users = User::factory()->count(5)->create(['rank_points' => 100]);
        foreach ($users->take(4) as $u) {
            $this->actor($u)->postJson('/api/tournaments/'.$t->id.'/join')->assertOk();
        }
        $this->postJson('/api/tournaments/'.$t->id.'/join')->assertOk();
        $this->assertDatabaseCount('tournament_participants', 4);
        $this->assertSame(100, Wallet::where('user_id', $users[0]->id)->value('balance'));
        $this->actor($users[4])->postJson('/api/tournaments/'.$t->id.'/join')->assertConflict();
        $this->actor($users[0])->postJson('/api/tournaments/'.$t->id.'/leave')->assertOk();
        $this->postJson('/api/tournaments/'.$t->id.'/leave')->assertOk();
        $this->assertSame(200, Wallet::where('user_id', $users[0]->id)->value('balance'));
        $this->actor($users[4])->postJson('/api/tournaments/'.$t->id.'/join')->assertOk();
    }

    public function test_start_requires_creator_full_capacity_and_available_accounts(): void
    {
        [$t, $users] = $this->filled();
        $this->actor($users[1])->postJson('/api/tournaments/'.$t->id.'/start')->assertForbidden();
        $this->actor($users[0])->postJson('/api/tournaments/'.$t->id.'/leave')->assertOk();
        $this->postJson('/api/tournaments/'.$t->id.'/start')->assertConflict();
        $this->postJson('/api/tournaments/'.$t->id.'/join')->assertOk();
        $users[1]->forceFill(['blocked_at' => now()])->save();
        $this->postJson('/api/tournaments/'.$t->id.'/start')->assertConflict();
        $this->assertDatabaseCount('online_games', 0);
        $this->assertSame('registration', $t->fresh()->status);
    }

    public function test_admin_can_start_and_cancel_another_users_tournament_with_audited_refunds(): void
    {
        [$first, $players] = $this->filled();
        [$second, $refunds] = $this->filled();
        $start = '/api/admin/tournaments/'.$first->id.'/start';
        $cancel = '/api/admin/tournaments/'.$second->id.'/cancel';
        $credentials = ['current_password' => 'password'];
        $this->actor($players[0])->postJson($start, $credentials)->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actor($admin)->postJson($start, ['current_password' => 'wrong'])->assertUnprocessable();
        $this->postJson($start, $credentials)->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson($cancel, $credentials)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson($cancel, $credentials)->assertConflict();
        foreach ($refunds as $player) {
            $this->assertSame(200, Wallet::where('user_id', $player->id)->value('balance'));
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'tournament_started', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tournament_cancelled', 'actor_id' => $admin->id]);
    }

    public function test_admin_settings_apply_to_new_rooms_and_tournament_defaults(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create();
        $url = '/api/admin/settings';
        $data = ['current_password' => 'password', 'online_turn_seconds' => 75, 'tournament_commission_percent' => 17];
        $this->actor($player)->patchJson($url, $data)->assertForbidden();
        $this->actor($admin)->getJson($url)->assertJsonPath('data.online_turn_seconds', 60);
        $this->patchJson($url, $data)->assertOk()->assertJsonPath('data.tournament_commission_percent', 17);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_settings_updated', 'actor_id' => $admin->id]);
        $this->actor($player)->postJson('/api/games')->assertCreated()->assertJsonPath('data.clock.turn_seconds', 75);
        $this->assertSame(17, (int) $this->create($player)->commission_percent);
        $this->actor($admin)->getJson('/api/admin/overview')->assertOk()
            ->assertJsonPath('stats.waiting_rooms', 1)->assertJsonPath('stats.active_tournaments', 0);
    }

    public function test_tournament_participants_open_assigned_game_with_account_and_outsider_cannot_play(): void
    {
        [$t, $users] = $this->filled();
        $this->actor($users[0])->postJson('/api/tournaments/'.$t->id.'/start')->assertOk();
        $game = OnlineGame::first();
        $white = User::find($game->white_user_id);
        $this->actor($white)->getJson('/api/games/'.$game->code)->assertOk()->assertJsonPath('data.my_color', 'white')->assertJsonPath('data.mode', 'tournament')->assertJsonPath('data.tournament_id', $t->id);
        $this->getJson('/api/games/'.$game->code.'/ticket')->assertOk();
        $this->actor(User::factory()->create())->getJson('/api/games/'.$game->code)->assertForbidden();
        $this->postJson('/api/games/'.$game->code.'/join')->assertConflict();
        $this->actor(null)->getJson('/api/games/'.$game->code)->assertUnauthorized();
        $this->actor($users[0])->postJson('/api/tournaments/'.$t->id.'/leave')->assertConflict();
        $this->postJson('/api/tournaments/'.$t->id.'/start')->assertConflict();
        $this->postJson('/api/tournaments/'.$t->id.'/cancel')->assertConflict();
    }

    public function test_full_round_robin_awards_only_podium_once_and_per_match_points_are_zero(): void
    {
        [$t, $users] = $this->filled(options: ['entry_fee' => 101, 'commission_percent' => 13]);
        $this->actor($users[0])->postJson('/api/tournaments/'.$t->id.'/start')->assertOk();
        for ($round = 1; $round <= 3; $round++) {
            $games = OnlineGame::where('tournament_id', $t->id)->where('tournament_round', $round)->get();
            $this->assertCount(2, $games);
            foreach ($games as $g) {
                $this->actor(User::find($g->black_user_id))->patchJson('/api/games/'.$g->code, ['action' => 'resign', 'revision' => $g->revision])->assertOk()->assertJsonPath('data.rank_change', null);
            }
            $this->assertDatabaseCount('rank_entries', 0);
            $this->travel(2)->minutes();
            app(TournamentService::class)->synchronize($t->id);
        }
        $t->refresh();
        $this->assertSame('finished', $t->status);
        $this->assertEquals(52, $t->site_revenue);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actor($admin)->getJson('/api/admin/wallet/transactions')->assertOk()->assertJsonPath('stats.tournament_revenue', 52);
        $this->assertDatabaseCount('online_games', 6);
        $pairs = OnlineGame::all()->map(function ($g) {
            $ids = [$g->white_user_id, $g->black_user_id];
            sort($ids);

            return implode('-', $ids);
        });
        $this->assertCount(6, $pairs->unique());
        $this->assertDatabaseCount('rank_entries', 3);
        $standings = app(TournamentService::class)->standings($t);
        foreach ($standings as $i => $row) {
            $prize = [150, 100, 60, 0][$i];
            $this->assertSame($prize, User::find($row['user_id'])->rank_points);
            $this->assertSame(3, $row['played']);
            $this->assertSame($prize, $row['prize']);
            $this->assertSame([177, 105, 70, 0][$i], $row['coin_prize']);
            $this->assertSame(99 + $row['coin_prize'], Wallet::where('user_id', $row['user_id'])->value('balance'));
        }
        for ($i = 0; $i < 3; $i++) {
            $this->actor($users[0])->getJson('/api/tournaments/'.$t->id)->assertOk();
        }
        $this->assertDatabaseCount('rank_entries', 3);
        $this->assertSame(310, (int) User::sum('rank_points'));
        $profile = app(CompetitiveRank::class)->profile(User::find($standings[0]['user_id']));
        $this->assertSame(150, $profile['points']);
        $this->assertSame('Bronze', $profile['tier']['name']);
        $this->assertSame(0, $profile['games']);
    }

    public function test_game_limit_draws_even_after_offline_time_and_rounds_catch_up_without_duplicate_games(): void
    {
        [$t, $users] = $this->filled();
        $this->actor($users[0])->postJson('/api/tournaments/'.$t->id.'/start')->assertOk();
        $game = OnlineGame::first();
        $this->travel(61)->seconds();
        $this->actor(User::find($game->white_user_id))->getJson('/api/games/'.$game->code)->assertOk()->assertJsonPath('data.reason', 'tournament_time')->assertJsonPath('data.winner', null);
        $this->assertSame('active', $t->fresh()->status);
        $this->travel(6)->minutes();
        $this->actor($users[0])->getJson('/api/tournaments/'.$t->id)->assertOk()->assertJsonPath('data.status', 'finished');
        $this->assertDatabaseCount('online_games', 6);
        $this->assertSame(6, OnlineGame::where('state->reason', 'tournament_time')->count());
        $this->getJson('/api/tournaments/'.$t->id)->assertOk();
        $this->assertDatabaseCount('online_games', 6);
        $this->assertDatabaseCount('rank_entries', 3);
    }

    public function test_odd_participant_count_has_one_bye_each_round_and_each_pair_meets_once(): void
    {
        [$t, $users] = $this->filled(5);
        $this->actor($users[0])->postJson('/api/tournaments/'.$t->id.'/start')->assertOk();
        $this->travel(11)->minutes();
        app(TournamentService::class)->synchronize($t->id);
        $games = OnlineGame::all();
        $this->assertCount(10, $games);
        foreach ($users as $user) {
            $this->assertSame(4, $games->filter(fn ($g) => $g->white_user_id === $user->id || $g->black_user_id === $user->id)->count());
        }
        $this->assertSame(5, $t->fresh()->current_round);
    }

    public function test_prize_failure_rolls_back_all_prizes_and_can_retry_safely(): void
    {
        [$t, $users] = $this->filled();
        $this->actor($users[0])->postJson('/api/tournaments/'.$t->id.'/start')->assertOk();
        $this->travel(7)->minutes();
        $counter = 0;
        RankEntry::creating(function () use (&$counter): void {
            $counter++;
            if ($counter === 2) {
                throw new RuntimeException('Simulated prize storage failure');
            }
        });
        try {
            app(TournamentService::class)->synchronize($t->id);
            $this->fail('Expected storage failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated prize storage failure', $e->getMessage());
        } finally {
            RankEntry::flushEventListeners();
        }
        $this->assertDatabaseCount('rank_entries', 0);
        $this->assertSame(0, (int) User::sum('rank_points'));
        $this->assertSame('active', $t->fresh()->status);
        $this->assertNull($t->fresh()->awarded_at);
        app(TournamentService::class)->synchronize($t->id);
        $this->assertDatabaseCount('rank_entries', 3);
        $this->assertSame(310, (int) User::sum('rank_points'));
    }

    public function test_profile_and_admin_keep_created_cancelled_active_and_finished_records_without_private_fields(): void
    {
        [$t, $users] = $this->filled();
        $cancelled = $this->create($users[0]);
        $this->postJson('/api/tournaments/'.$cancelled->id.'/cancel')->assertOk();
        $this->postJson('/api/tournaments/'.$t->id.'/start')->assertOk();
        $this->getJson('/api/account/tournaments')->assertOk()->assertJsonPath('stats.created', 2)->assertJsonPath('stats.participated', 1);
        $this->travel(7)->minutes();
        $this->getJson('/api/tournaments/'.$t->id)->assertOk();
        $this->getJson('/api/account/tournaments')->assertOk()->assertJsonPath('stats.podiums', 1)->assertJsonPath('stats.points', 150);
        $other = User::factory()->create();
        $this->actor($other)->getJson('/api/account/tournaments')->assertOk()->assertJsonPath('stats.created', 0)->assertJsonPath('stats.participated', 0)->assertJsonCount(0, 'data');
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actor($admin)->getJson('/api/admin/tournaments')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertStringNotContainsString($users[0]->email, $response->getContent());
        $this->getJson('/api/admin/tournaments?status=cancelled')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/tournaments/'.$t->id)->assertOk()->assertJsonCount(6, 'data.games');
    }
}

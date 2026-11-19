<?php

namespace App\Services;

use App\Models\OnlineGame;
use App\Models\RankEntry;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TournamentService
{
    public const PRIZES = [150, 100, 60];

    public function __construct(private CheckersEngine $engine, private GameClock $clock) {}

    /** A write to the tournament row serializes enrolment, round progression and final prizes. */
    public function locked(int $id, callable $action): mixed
    {
        return DB::transaction(function () use ($id, $action): mixed {
            Tournament::whereKey($id)->update(['updated_at' => now()]);

            return $action(Tournament::findOrFail($id));
        });
    }

    public function synchronize(int $id): Tournament
    {
        return $this->locked($id, function (Tournament $t): Tournament {
            while ($t->status === 'active') {
                $games = OnlineGame::where('tournament_id', $t->id)->where('tournament_round', $t->current_round)->get();
                foreach ($games as $game) {
                    $this->clock->synchronize($game);
                }
                if (OnlineGame::where('tournament_id', $t->id)->where('tournament_round', $t->current_round)->where('phase', 'active')->exists()) {
                    break;
                }
                if (now()->lt($t->round_deadline_at)) {
                    break;
                }
                $count = TournamentParticipant::where('tournament_id', $t->id)->count();
                $rounds = ($count % 2 ? $count + 1 : $count) - 1;
                if ($t->current_round >= $rounds) {
                    $this->finish($t);
                    break;
                }
                $start = $t->round_deadline_at;
                $t->forceFill(['current_round' => $t->current_round + 1, 'round_started_at' => $start, 'round_deadline_at' => $start->addMinutes($t->round_minutes)])->save();
                $this->createRound($t);
            }

            return $t->refresh();
        });
    }

    public function createRound(Tournament $t): void
    {
        $ids = TournamentParticipant::where('tournament_id', $t->id)->orderBy('seed')->pluck('user_id')->all();
        if (count($ids) % 2) {
            $ids[] = null;
        }
        for ($r = 1; $r < $t->current_round; $r++) {
            $last = array_pop($ids);
            array_splice($ids, 1, 0, [$last]);
        }
        for ($i = 0; $i < count($ids) / 2; $i++) {
            $white = $ids[$i];
            $black = $ids[count($ids) - 1 - $i];
            if ($white === null || $black === null) {
                continue;
            }
            if (($t->current_round + $i) % 2 === 0) {
                [$white, $black] = [$black, $white];
            }
            OnlineGame::create([
                'code' => strtoupper(Str::random(10)), 'mode' => 'tournament', 'tournament_id' => $t->id, 'tournament_round' => $t->current_round,
                'white_user_id' => $white, 'black_user_id' => $black, 'white_key' => hash('sha256', Str::random(64)), 'black_key' => hash('sha256', Str::random(64)),
                'state' => $this->engine->initial(), 'phase' => 'active', 'revision' => 0, 'turn_seconds' => 60,
                'started_at' => $t->round_started_at, 'white_seen_at' => $t->round_started_at, 'black_seen_at' => $t->round_started_at,
                'turn_deadline_at' => $t->round_started_at->addSeconds(60),
                'game_deadline_at' => $t->round_started_at->addMinutes($t->game_minutes),
            ]);
        }
    }

    public function finances(Tournament $t): array
    {
        $gross = (int) $t->capacity * (int) $t->entry_fee;
        $commission = intdiv($gross * (int) $t->commission_percent, 100);
        $pool = $gross - $commission;
        $second = intdiv($pool * CompetitionWallet::PODIUM_PERCENTAGES[1], 100);
        $third = intdiv($pool * CompetitionWallet::PODIUM_PERCENTAGES[2], 100);

        return ['gross' => $gross, 'commission' => $commission, 'pool' => $pool,
            'prizes' => [$pool - $second - $third, $second, $third]];
    }

    public function standings(Tournament $t): array
    {
        $participants = TournamentParticipant::where('tournament_id', $t->id)->orderByDesc('entry_points')->orderBy('id')->get();
        $names = User::withTrashed()->whereIn('id', $participants->pluck('user_id'))->pluck('name', 'id');
        $rows = [];
        foreach ($participants as $p) {
            $rows[$p->user_id] = ['user_id' => $p->user_id, 'name' => $names[$p->user_id] ?? 'Conta eliminada', 'score' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'played' => 0, 'entry_points' => $p->entry_points, 'order' => $p->id, 'prize' => 0];
        }
        foreach (OnlineGame::where('tournament_id', $t->id)->where('phase', 'finished')->get() as $game) {
            foreach (['white' => $game->white_user_id, 'black' => $game->black_user_id] as $color => $id) {
                $winner = $game->state['winner'];
                $result = $winner === null ? 'draws' : ($winner === $color ? 'wins' : 'losses');
                $rows[$id][$result]++;
                $rows[$id]['played']++;
                $rows[$id]['score'] += $result === 'wins' ? 3 : ($result === 'draws' ? 1 : 0);
            }
        }
        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($b['wins'] <=> $a['wins']) ?: ($b['entry_points'] <=> $a['entry_points']) ?: ($a['order'] <=> $b['order']));
        $prizes = $this->finances($t)['prizes'];
        foreach ($rows as $i => &$row) {
            $row['position'] = $i + 1;
            $row['prize'] = $t->status === 'finished' ? (self::PRIZES[$i] ?? 0) : 0;
            $row['coin_prize'] = ($prizes[$i] ?? 0);
            unset($row['order']);
        }

        return $rows;
    }

    private function finish(Tournament $t): void
    {
        if ($t->awarded_at !== null) {
            return;
        }
        foreach (array_slice($this->standings($t), 0, 3) as $i => $row) {
            $user = User::withTrashed()->findOrFail($row['user_id']);
            app(CompetitionWallet::class)->move($user->id, $row['coin_prize'], 'prize', 'Torneio #'.$t->id);
            $before = (int) $user->rank_points;
            $user->forceFill(['rank_points' => $before + self::PRIZES[$i]])->save();
            RankEntry::create(['game_id' => null, 'tournament_id' => $t->id, 'user_id' => $user->id, 'outcome' => 'tournament', 'delta' => self::PRIZES[$i], 'previous_points' => $before, 'resulting_points' => $user->rank_points]);
        }
        $t->forceFill(['status' => 'finished', 'finished_at' => $t->round_deadline_at, 'awarded_at' => now(), 'site_revenue' => $this->finances($t)['commission']])->save();
    }
}

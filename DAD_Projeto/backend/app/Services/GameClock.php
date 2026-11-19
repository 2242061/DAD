<?php

namespace App\Services;

use App\Models\OnlineGame;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GameClock
{
    public const ABANDON_SECONDS = 30;

    public function __construct(private GameRealtime $realtime, private CompetitiveRank $ranks) {}

    public function playerSeen(OnlineGame $game, string $color): OnlineGame
    {
        $game = $this->synchronize($game);
        if ($game->phase !== 'active') {
            return $game;
        }

        $column = $color.'_seen_at';
        $now = now();
        if ($game->{$column} === null || $game->{$column}->lte($now->copy()->subSeconds(5))) {
            OnlineGame::whereKey($game->id)->where('phase', 'active')
                ->where(fn ($query) => $query->whereNull($column)->orWhere($column, '<', $now->format('Y-m-d H:i:s.v')))
                ->update([$column => $now->format('Y-m-d H:i:s.v')]);
        }

        return $game->refresh();
    }

    public function synchronize(OnlineGame $game): OnlineGame
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            if ($game->phase !== 'active') {
                return $game;
            }

            $now = now();
            $state = $game->state;
            $deadline = $game->turn_deadline_at;
            $winner = $this->opponent($state['turn']);
            $reason = 'timeout';
            $expected = [];
            if ($game->game_deadline_at && ($deadline === null || $game->game_deadline_at->lte($deadline))) {
                $deadline = $game->game_deadline_at;
                $winner = null;
                $reason = 'tournament_time';
            }

            if ($absence = $this->abandonment($game, $now->toImmutable())) {
                [$abandonedColor, $expiredAt] = $absence;
                if ($deadline === null || $expiredAt->lt($deadline)) {
                    $deadline = $expiredAt;
                    $winner = $this->opponent($abandonedColor);
                    $reason = 'abandonment';
                    $expected = [
                        'white_seen_at' => $game->white_seen_at->format('Y-m-d H:i:s.v'),
                        'black_seen_at' => $game->black_seen_at->format('Y-m-d H:i:s.v'),
                    ];
                }
            }

            if ($deadline && $now->gte($deadline)) {
                $state = $this->finish($state, $winner, $reason);
                $committed = $this->ranks->commit($game, [
                    'state' => json_encode($state), 'revision' => $state['revision'], 'phase' => 'finished',
                    'finished_at' => $deadline->format('Y-m-d H:i:s.v'), 'turn_deadline_at' => null,
                ], $expected);
                if ($committed !== null) {
                    DB::afterCommit(fn () => $this->realtime->publish($committed));

                    return $committed;
                }
            } elseif ($game->turn_deadline_at === null) {
                OnlineGame::whereKey($game->id)->where('revision', $game->revision)->whereNull('turn_deadline_at')->update([
                    'started_at' => $now->format('Y-m-d H:i:s.v'),
                    'turn_deadline_at' => $now->copy()->addSeconds($game->turn_seconds)->format('Y-m-d H:i:s.v'),
                ]);
            } else {
                return $game;
            }
            $game->refresh();
        }

        abort(409, 'A partida está a atualizar. Tenta novamente.');
    }

    private function abandonment(OnlineGame $game, CarbonImmutable $now): ?array
    {
        if (! $game->white_seen_at || ! $game->black_seen_at) {
            return null;
        }
        $white = $game->white_seen_at->addSeconds(self::ABANDON_SECONDS);
        $black = $game->black_seen_at->addSeconds(self::ABANDON_SECONDS);
        if ($white->lte($now) && $black->gt($now)) {
            return ['white', $white];
        }
        if ($black->lte($now) && $white->gt($now)) {
            return ['black', $black];
        }

        return null;
    }

    public function practice(array $state): array
    {
        $now = now()->getTimestampMs();
        $state['clock'] ??= ['started_at' => $now, 'deadline_at' => $now + 60000, 'finished_at' => null, 'turn_seconds' => 60];
        $state['clock']['server_now'] = $now;
        $state['clock']['timeout_rule'] = 'loss';
        if ($state['status'] === 'active' && $state['clock']['deadline_at'] !== null && $now >= $state['clock']['deadline_at']) {
            $state = $this->finish($state, $this->opponent($state['turn']), 'timeout');
            $state['clock']['finished_at'] = $state['clock']['deadline_at'];
            $state['clock']['deadline_at'] = null;
        }

        return $state;
    }

    private function finish(array $state, ?string $winner, string $reason): array
    {
        $state['status'] = 'finished';
        $state['winner'] = $winner;
        $state['reason'] = $reason;
        $state['revision']++;
        unset($state['draw_offer']);

        return $state;
    }

    private function opponent(string $color): string
    {
        return $color === 'white' ? 'black' : 'white';
    }

    public function data(OnlineGame $game): array
    {
        return [
            'server_now' => now()->getTimestampMs(), 'turn_seconds' => $game->turn_seconds,
            'started_at' => $game->started_at?->getTimestampMs(),
            'deadline_at' => $game->turn_deadline_at?->getTimestampMs(),
            'finished_at' => $game->finished_at?->getTimestampMs(), 'timeout_rule' => 'loss',
            'game_deadline_at' => $game->game_deadline_at?->getTimestampMs(),
        ];
    }
}

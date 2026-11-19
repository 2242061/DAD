<?php

namespace App\Services;

use App\Models\OnlineGame;
use App\Models\RankEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompetitiveRank
{
    public const WIN = 30;

    public const LOSS = -15;

    public function tiers(): array
    {
        $names = ['Novato', 'Bronze', 'Prata', 'Ouro', 'Platina', 'Diamante', 'Mestre', 'Grão-Mestre', 'Lenda', 'Imortal'];
        $thresholds = [0, 100, 250, 450, 700, 1000, 1350, 1750, 2200, 2800];

        return array_map(fn (int $index): array => ['level' => $index + 1, 'name' => $names[$index], 'minimum' => $thresholds[$index]], array_keys($names));
    }

    public function tier(int $points): array
    {
        $tiers = $this->tiers();
        $current = $tiers[0];
        foreach ($tiers as $tier) {
            if ($points >= $tier['minimum']) {
                $current = $tier;
            }
        }
        $next = $tiers[$current['level']] ?? null;

        return $current + ['next' => $next, 'points_to_next' => $next ? $next['minimum'] - $points : 0,
            'progress' => $next ? (int) floor(100 * ($points - $current['minimum']) / ($next['minimum'] - $current['minimum'])) : 100];
    }

    /** Commit the result and all point changes together; SQLite serializes writers at this first update. */
    public function commit(OnlineGame $game, array $attributes, array $expected = []): ?OnlineGame
    {
        return DB::transaction(function () use ($game, $attributes, $expected): ?OnlineGame {
            if (OnlineGame::whereKey($game->id)->where('revision', $game->revision)->where($expected)->update($attributes) !== 1) {
                return null;
            }
            $game->refresh();
            $this->settle($game);

            return $game->refresh();
        });
    }

    public function settle(OnlineGame $game): void
    {
        if ($game->mode !== 'ranked' || $game->phase !== 'finished' || $game->ranked_scored_at !== null
            || $game->state['reason'] === 'cancelled' || ! $game->white_user_id || ! $game->black_user_id
            || $game->white_user_id === $game->black_user_id) {
            return;
        }
        DB::transaction(function () use ($game): void {
            $claimed = OnlineGame::whereKey($game->id)->where('mode', 'ranked')->where('phase', 'finished')
                ->whereNull('ranked_scored_at')->update(['ranked_scored_at' => now()]);
            if ($claimed !== 1) {
                return;
            }
            foreach (['white' => $game->white_user_id, 'black' => $game->black_user_id] as $color => $id) {
                $user = User::withTrashed()->findOrFail($id);
                $winner = $game->state['winner'];
                $outcome = $winner === null ? 'draw' : ($winner === $color ? 'win' : 'loss');
                $fee = (int) $game->entry_fee;
                if ($fee && $outcome !== 'loss') {
                    app(CompetitionWallet::class)->move($id, $outcome === 'draw' ? $fee : 2 * $fee,
                        $outcome === 'draw' ? 'refund' : 'prize', 'Competitivo '.$game->code);
                }
                $before = (int) $user->rank_points;
                $after = max(0, $before + ($outcome === 'win' ? self::WIN : ($outcome === 'loss' ? self::LOSS : 0)));
                $user->forceFill(['rank_points' => $after])->save();
                RankEntry::create(['game_id' => $game->id, 'user_id' => $id, 'outcome' => $outcome,
                    'delta' => $after - $before, 'previous_points' => $before, 'resulting_points' => $after]);
            }
        });
    }

    public function profile(User $user): array
    {
        $user->refresh();
        $counts = RankEntry::where('user_id', $user->id)->selectRaw('outcome, COUNT(*) as total')->groupBy('outcome')->pluck('total', 'outcome');
        $wins = (int) ($counts['win'] ?? 0);
        $losses = (int) ($counts['loss'] ?? 0);
        $draws = (int) ($counts['draw'] ?? 0);
        $games = $wins + $losses + $draws;

        return ['points' => (int) $user->rank_points, 'tier' => $this->tier((int) $user->rank_points),
            'games' => $games, 'wins' => $wins, 'losses' => $losses, 'draws' => $draws,
            'win_rate' => $games ? round(100 * $wins / $games, 1) : 0,
            'win_loss_ratio' => $losses ? round($wins / $losses, 2) : null,
            'tournament_points' => (int) RankEntry::where('user_id', $user->id)->whereNotNull('tournament_id')->sum('delta'),
            'tournament_podiums' => RankEntry::where('user_id', $user->id)->whereNotNull('tournament_id')->count(),
            'rules' => ['win' => self::WIN, 'loss' => self::LOSS, 'draw' => 0, 'minimum' => 0]];
    }
}

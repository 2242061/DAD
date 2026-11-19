<?php

namespace App\Http\Controllers;

use App\Models\OnlineGame;
use App\Models\RankEntry;
use App\Models\User;
use App\Services\CompetitiveRank;
use App\Services\GameClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerHistoryController extends Controller
{
    public function index(Request $request, GameClock $clock, CompetitiveRank $ranks): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $id = $request->user()->id;
        $query = OnlineGame::query()->where(fn (Builder $q) => $q->where('white_user_id', $id)->orWhere('black_user_id', $id))
            ->whereNotNull('black_key')->whereIn('phase', ['active', 'finished']);
        $rows = (clone $query)->latest('started_at')->latest('created_at')->latest('id')->paginate(10);
        $entries = RankEntry::where('user_id', $id)->whereIn('game_id', $rows->getCollection()->pluck('id'))->get()->keyBy('game_id');
        $names = User::withTrashed()->whereIn('id', $rows->getCollection()->flatMap(fn (OnlineGame $g) => [$g->white_user_id, $g->black_user_id])->filter())->pluck('name', 'id');
        $data = $rows->getCollection()->map(function (OnlineGame $game) use ($id, $clock, $names, $entries, $ranks): array {
            $game = $clock->synchronize($game);
            $entry = $entries[$game->id] ?? null;
            if ($entry === null && $game->mode === 'ranked' && $game->phase === 'finished') {
                $ranks->settle($game);
                $entry = RankEntry::where('game_id', $game->id)->where('user_id', $id)->first();
            }
            $color = $game->white_user_id === $id ? 'white' : 'black';
            $state = $game->state;
            $opponent = $color === 'white' ? $game->black_user_id : $game->white_user_id;
            $result = $game->phase !== 'finished' ? 'active' : ($state['winner'] === null ? 'draw' : ($state['winner'] === $color ? 'win' : 'loss'));

            return [
                'id' => $game->id, 'code' => $game->code, 'mode' => $game->mode, 'rank_delta' => $entry?->delta, 'color' => $color, 'opponent' => $opponent ? ($names[$opponent] ?? 'Conta eliminada') : 'Visitante',
                'result' => $result, 'reason' => $state['reason'], 'started_at' => $game->started_at?->toIso8601String(),
                'finished_at' => $game->finished_at?->toIso8601String(),
                'duration_seconds' => $game->started_at && ($game->phase !== 'finished' || $game->finished_at) ? max(0, (int) $game->started_at->diffInSeconds($game->finished_at ?? now())) : null,
                'moves' => count(array_filter($state['history'], fn (array $move): bool => ($move['type'] ?? 'move') === 'move')),
                'history' => $state['history'], 'board' => $state['board'],
            ];
        });
        $completed = (clone $query)->where('phase', 'finished');
        $wins = (clone $completed)->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('white_user_id', $id)->where('state->winner', 'white'))
            ->orWhere(fn (Builder $q) => $q->where('black_user_id', $id)->where('state->winner', 'black')))->count();
        $draws = (clone $completed)->whereNull('state->winner')->count();
        $finished = $completed->count();
        $active = (clone $query)->where('phase', 'active')->count();

        return response()->json([
            'ranking' => $ranks->profile($request->user()),
            'data' => $data, 'stats' => ['games' => $finished + $active, 'finished' => $finished, 'wins' => $wins, 'draws' => $draws, 'losses' => $finished - $wins - $draws, 'active' => $active],
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ])->header('Cache-Control', 'private, no-store');
    }
}

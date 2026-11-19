<?php

namespace App\Http\Controllers;

use App\Models\OnlineGame;
use App\Models\User;
use App\Services\GameClock;
use App\Services\GameRealtime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class SpectatorController extends Controller
{
    public function index(): JsonResponse
    {
        $games = $this->publicGames()->where('phase', 'active')->latest('started_at')->orderBy('id')->simplePaginate(20);
        $names = User::whereIn('id', $games->getCollection()->flatMap(fn (OnlineGame $game) => [$game->white_user_id, $game->black_user_id]))
            ->pluck('name', 'id');

        return response()->json([
            'data' => $games->getCollection()->map(fn (OnlineGame $game) => $this->summary($game, $names->all())),
            'page' => $games->currentPage(),
            'has_more' => $games->hasMorePages(),
        ])->header('Cache-Control', 'no-store');
    }

    public function show(string $code, GameClock $clock): JsonResponse
    {
        $game = $clock->synchronize($this->publicGames()->where('code', $code)->firstOrFail());
        $names = User::whereIn('id', [$game->white_user_id, $game->black_user_id])->pluck('name', 'id')->all();
        $state = array_intersect_key($game->state, array_flip(['board', 'turn', 'status', 'winner', 'reason', 'history']));

        return response()->json(['data' => $this->summary($game, $names) + $state + [
            'id' => $game->id, 'revision' => $game->revision, 'clock' => $clock->data($game),
            'legal_moves' => [],
        ]])->header('Cache-Control', 'no-store');
    }

    public function ticket(string $code, GameRealtime $realtime): JsonResponse
    {
        $game = $this->publicGames()->where('code', $code)->firstOrFail();

        return response()->json(['ticket' => $realtime->ticket($game, 'spectator')])->header('Cache-Control', 'no-store');
    }

    private function publicGames(): Builder
    {
        return OnlineGame::query()->whereIn('mode', ['ranked', 'tournament'])
            ->whereIn('phase', ['active', 'finished'])->whereNotNull('started_at');
    }

    private function summary(OnlineGame $game, array $names): array
    {
        return [
            'code' => $game->code, 'mode' => $game->mode, 'phase' => $game->phase,
            'players' => [
                'white' => $names[$game->white_user_id] ?? 'Jogador das brancas',
                'black' => $names[$game->black_user_id] ?? 'Jogador das pretas',
            ],
            'tournament_id' => $game->tournament_id, 'tournament_round' => $game->tournament_round,
        ];
    }
}

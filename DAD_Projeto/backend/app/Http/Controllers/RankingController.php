<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CompetitiveRank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RankingController extends Controller
{
    public function index(Request $request, CompetitiveRank $ranks): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $users = User::whereNull('blocked_at')->whereHas('rankEntries')->orderByDesc('rank_points')->orderBy('id')
            ->select(['id', 'name', 'rank_points'])->paginate(20);
        $data = $users->getCollection()->map(fn (User $user, int $index): array => [
            'position' => ($users->currentPage() - 1) * 20 + $index + 1,
            'id' => $user->id, 'name' => $user->name, 'points' => (int) $user->rank_points, 'tier' => $ranks->tier((int) $user->rank_points),
        ]);

        return response()->json(['data' => $data, 'tiers' => $ranks->tiers(),
            'rules' => ['win' => CompetitiveRank::WIN, 'loss' => CompetitiveRank::LOSS, 'draw' => 0, 'minimum' => 0],
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()],
        ])->header('Cache-Control', 'no-store');
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\OnlineGame;
use App\Models\Tournament;
use App\Models\User;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminOverviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', 'in:active,blocked,deleted'],
        ]);
        $query = ($data['status'] ?? '') === 'deleted' ? User::onlyTrashed() : User::query();
        if ($search = $data['q'] ?? '') {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
        }
        if ($role = $data['role'] ?? '') {
            $query->where('role', $role);
        }
        if (($data['status'] ?? '') === 'blocked') {
            $query->whereNotNull('blocked_at');
        } elseif (($data['status'] ?? '') === 'active') {
            $query->whereNull('blocked_at');
        }
        $users = $query->latest('id')->paginate(10);

        return response()->json([
            'stats' => [
                'total' => User::count(),
                'players' => User::where('role', UserRole::Player)->count(),
                'admins' => User::where('role', UserRole::Admin)->count(),
                'blocked' => User::whereNotNull('blocked_at')->count(),
                'deleted' => User::onlyTrashed()->count(),
                'waiting_rooms' => OnlineGame::where('phase', 'waiting')->count(),
                'active_games' => OnlineGame::where('phase', 'active')->count(),
                'finished_games' => OnlineGame::where('phase', 'finished')->whereNotNull('started_at')->count(),
                'active_tournaments' => Tournament::where('status', 'active')->count(),
                'finished_tournaments' => Tournament::where('status', 'finished')->count(),
            ],
            'users' => UserResource::collection($users->items()),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}

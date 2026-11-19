<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): UserResource
    {
        $user = new User($request->safe()->only(['name', 'email', 'password']));
        $user->role = UserRole::Player;
        $user->save();

        $user->sendEmailVerificationNotification();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_version', $user->auth_version);

        return new UserResource($user);
    }

    public function login(LoginRequest $request): UserResource
    {
        if (! Auth::guard('web')->attempt($request->validated() + ['blocked_at' => null])) {
            throw ValidationException::withMessages([
                'email' => 'O email ou a palavra-passe estão incorretos.',
            ]);
        }

        $request->session()->regenerate();

        $request->session()->put('auth_version', Auth::guard('web')->user()->auth_version);

        return new UserResource(Auth::guard('web')->user());
    }

    public function me(Request $request): JsonResponse
    {
        return (new UserResource($request->user()))->response()->setStatusCode(200);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}

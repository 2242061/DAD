<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && (int) $request->session()->get('auth_version', 0) !== (int) $request->user()?->auth_version) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'A sessão terminou. Entra novamente.'], 401);
        }
        if ($request->user()?->blocked_at || $request->user()?->trashed()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Esta conta está indisponível. Contacta um administrador.',
                'code' => 'account_unavailable',
            ], 403);
        }

        return $next($request);
    }
}

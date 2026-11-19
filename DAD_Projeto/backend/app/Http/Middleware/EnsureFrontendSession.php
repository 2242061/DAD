<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFrontendSession
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->hasSession(), 403, 'Inicia este pedido a partir da aplicação web.');

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('agent_host.authenticated') === true) {
            return $next($request);
        }

        if ($request->isMethod('GET') && $request->path() === '/' && ($request->filled('room') || $request->filled('invite'))) {
            return $next($request);
        }

        return $request->expectsJson() || $request->is('api/*')
            ? response()->json(['message' => 'Unauthenticated.'], 401)
            : redirect()->guest(route('login'));
    }
}

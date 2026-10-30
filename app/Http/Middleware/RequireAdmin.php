<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionUser = $request->session()->get('agent_host.user');
        $role = is_array($sessionUser) ? ($sessionUser['role'] ?? 'admin') : 'admin';

        if ($role === 'admin') {
            return $next($request);
        }

        return response()->json([
            'message' => 'Forbidden: Only users with the admin role can access live VPS Fleet sessions or manage projects.',
        ], 403);
    }
}

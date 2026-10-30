<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionUser = $request->session()->get('agent_host.user');
        $userId = $request->session()->get('agent_host.user_id') ?? ($sessionUser['id'] ?? null);

        if ($userId && Schema::hasTable('users')) {
            $dbUser = User::find($userId);
            if ($dbUser) {
                $sessionUser['role'] = $dbUser->role ?: 'no_role';
                $request->session()->put('agent_host.user', $sessionUser);
            }
        }

        $role = is_array($sessionUser) ? ($sessionUser['role'] ?? 'admin') : 'admin';

        if ($role === 'admin') {
            return $next($request);
        }

        return response()->json([
            'message' => 'Forbidden: Only users with the admin role can access live VPS Fleet sessions or manage projects.',
        ], 403);
    }
}

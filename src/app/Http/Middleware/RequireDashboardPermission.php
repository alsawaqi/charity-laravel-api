<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireDashboardPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$permissionKeys): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (empty($permissionKeys)) {
            return $next($request);
        }

        foreach ($permissionKeys as $permissionKey) {
            if ($user->hasDashboardPermission($permissionKey)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'You do not have permission to access this resource.',
        ], 403);
    }
}

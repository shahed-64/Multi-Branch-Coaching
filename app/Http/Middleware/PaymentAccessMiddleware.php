<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentAccessMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $staff = $request->user();

        if (!$staff) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $allowedRoles = [
            'Manager',
            'Branch Manager',
            'Branch Accountant',
        ];

        if (!in_array($staff->role, $allowedRoles)) {
            return response()->json([
                'status' => false,
                'message' => 'Access Denied'
            ], 403);
        }

        return $next($request);
    }
}

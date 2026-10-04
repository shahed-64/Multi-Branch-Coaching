<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Services\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BranchContextMiddleware
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {

        $authUser = $request->user();

        /*
         * If user is not authenticated,
         * let auth middleware handle it.
         */
        if (!$authUser) {
            return $next($request);
        }

        $branchContext = app(BranchContext::class);

        /*
         * --------------------------------------------------------------
         * MANAGER
         * --------------------------------------------------------------
         */
        if ($authUser->role === 'Manager') {

            $selectedBranchId = $request->header('X-Branch-Id');

            /*
             * All Branches
             */
            if (
                $selectedBranchId === null ||
                $selectedBranchId === ''
            ) {
                $branchContext->set(null);

                return $next($request);
            }

            /*
             * Validate selected branch
             */
            $branch = Branch::find($selectedBranchId);

            if (!$branch) {
                return response()->json([
                    'status' => false,
                    'message' => 'Selected branch not found.',
                ], 404);
            }

            /*
             * Set selected branch
             */
            $branchContext->set($branch->id);

            return $next($request);
        }

        /*
         * --------------------------------------------------------------
         * NON-MANAGER
         * --------------------------------------------------------------
         */

        if (!$authUser->branch_id) {
            return response()->json([
                'status' => false,
                'message' =>
                    'Your account is not assigned to any branch.',
            ], 403);
        }

        /*
         * Set own branch
         */
        $branchContext->set($authUser->branch_id);

        return $next($request);
    }
}

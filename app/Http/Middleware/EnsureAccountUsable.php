<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For routes outside the Filament panel (file download, locale switch): the account
 * must be active, not expired, and enrolled in MFA (M9-P0-02). Without this, a user who
 * signed in but skipped MFA enrolment could call these routes directly.
 */
final class EnsureAccountUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isUsable() || blank($user->getAppAuthenticationSecret())) {
            abort(403);
        }

        return $next($request);
    }
}

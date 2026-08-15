<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces users.must_reset_password.
 *
 * The column exists because the import scrambled password hashes for
 * anonymisation — those accounts can authenticate against nothing, so locally
 * the flag is mostly decorative. It matters at cutover: any account whose
 * legacy hash can't be carried across intact gets flagged here rather than
 * silently left with a password nobody knows.
 *
 * Without an enforcement point, the column is a note-to-self that never fires.
 *
 * Register in bootstrap/app.php:
 *
 *     ->withMiddleware(function (Middleware $middleware) {
 *         $middleware->alias([
 *             'password.usable' => \App\Http\Middleware\EnsurePasswordIsUsable::class,
 *         ]);
 *     })
 *
 * then apply alongside 'auth' on your authenticated route group.
 */
class EnsurePasswordIsUsable
{
    /**
     * Routes the user must still reach while flagged, or they'd be redirected
     * away from the very page that clears the flag.
     *
     * @var list<string>
     */
    protected array $except = [
        'password.*',
        'logout',
        'verification.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_reset_password) {
            return $next($request);
        }

        foreach ($this->except as $pattern) {
            if ($request->routeIs($pattern)) {
                return $next($request);
            }
        }

        // Don't bounce XHR to an HTML page — Livewire and fetch() callers get a
        // status they can act on instead of a redirect they'll render as HTML.
        if ($request->expectsJson()) {
            abort(403, 'Set a new password before continuing.');
        }

        return redirect()
            ->route('password.request')
            ->with('status', 'Set a new password to finish restoring your account.');
    }
}

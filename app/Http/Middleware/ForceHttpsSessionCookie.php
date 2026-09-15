<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the session cookie's Secure flag from the REQUEST SCHEME.
 *
 * Why this is middleware and not config/session.php:
 *
 * config/session.php is evaluated at boot, including for CLI commands, where
 * no request exists. Calling request() there throws "Target class [request]
 * does not exist" and breaks every artisan command. The scheme is per-request
 * data, so it is resolved per request.
 *
 * Why not the previous approach (key off APP_ENV=production):
 *
 * A production app reached over plain HTTP - a proxy that does not forward
 * X-Forwarded-Proto, a staging box, a rehearsal run - then sends
 * `Set-Cookie: ...; secure` over http://. Browsers MUST discard that cookie, so
 * the session never persists and login becomes impossible with no error
 * anywhere. A production rehearsal caught it: 21 of 25 E2E tests failed, every
 * one that needs a session, and the only symptom was a form that never
 * navigated.
 *
 * isSecure() is proxy-aware (see trustProxies in bootstrap/app.php), so behind
 * a TLS terminator this still resolves to true and the flag is set correctly.
 * An explicit SESSION_SECURE_COOKIE still wins, so a deployment can force it.
 */
class ForceHttpsSessionCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        // An explicit setting always wins - someone may need to force the flag
        // on (behind a proxy that strips X-Forwarded-Proto) or off (a local
        // HTTP run).
        if (config('session.secure_explicit') === null) {
            config(['session.secure' => $request->isSecure()]);
        }

        return $next($request);
    }
}

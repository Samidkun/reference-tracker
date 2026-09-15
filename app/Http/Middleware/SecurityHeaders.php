<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security response headers.
 *
 * The app shipped without any of these. They are cheap, they apply to every
 * response, and they close the standard browser-side attack classes:
 * clickjacking, MIME sniffing, referrer leakage, and (for the CSP) most
 * injected-script paths.
 *
 * CSP notes: Vite injects inline <script> for the dev server and Inertia
 * embeds the page payload in a data-page attribute, so 'unsafe-inline' is
 * required for scripts in development. In production the built assets are
 * external files, so the inline allowance can be dropped — that is what the
 * environment check below does.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // A fresh nonce per request. Ziggy's @routes emits a ~23 kB INLINE
        // script; without a nonce (or 'unsafe-inline') a browser blocks it in
        // production and the whole app dies, because `route()` never exists.
        // A nonce keeps the strict policy AND allows that one script.
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        // The nonce travels on the REQUEST, not in the container under a string
        // key. A container binding is global mutable state: any other request
        // in the same process could read a stale value, and the CSP builder
        // silently degraded to an empty nonce if nothing had bound it. A
        // request attribute is scoped to exactly this request.
        $request->attributes->set('csp_nonce', $nonce);
        View::share('cspNonce', $nonce);

        // Laravel's own @vite pipeline emits an INLINE prefetch script
        // (Illuminate\Foundation\Vite::prefetch) as well as the module tag.
        // Without a nonce those are blocked in production. This is the
        // framework's supported hook for exactly that.
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $headers = [
            // Do not let a browser guess a content type we did not declare.
            'X-Content-Type-Options' => 'nosniff',
            // No framing at all: this app is never legitimately embedded.
            'X-Frame-Options' => 'DENY',
            // Do not leak the full URL (which can contain ids) to third parties.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Nothing here needs camera/mic/geolocation.
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            // Responses are for this origin only; stops another site from
            // embedding our JSON/asset responses as a sub-resource.
            'Cross-Origin-Resource-Policy' => 'same-origin',
            // No Flash/PDF cross-domain policy files are served here.
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        // HSTS only makes sense over TLS; sending it on http://localhost
        // would be ignored at best and is misleading at worst.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        $headers['Content-Security-Policy'] = $this->contentSecurityPolicy($nonce);

        foreach ($headers as $name => $value) {
            // Never clobber a header the app or a dependency already set.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * Build the CSP for this request.
     *
     * The nonce is a PARAMETER, deliberately: it is per-request data, and
     * reading it back out of global state is how it silently became empty.
     */
    private function contentSecurityPolicy(string $nonce): string
    {
        // connect-src must include the Vite websocket in local dev, or HMR
        // is blocked. Building it conditionally keeps the directive appearing
        // exactly ONCE — a duplicated directive is technically valid but the
        // browser uses only the last one, which silently drops the first.
        $connect = ["'self'", 'https://api.crossref.org'];
        if (app()->environment('local', 'testing')) {
            // NOTE: no bracketed IPv6 literal (ws://[::1]:5173). A browser
            // rejects it as an invalid CSP source and logs a violation on
            // every page load. `localhost` covers the dev server.
            $connect[] = 'ws://localhost:5173';
        }

        // style-src needs 'unsafe-inline' in every environment: Inertia's
        // progress bar (nprogress) injects a <style> block at runtime and
        // offers no nonce hook. This is a deliberate, bounded concession —
        // inline STYLE is far lower risk than inline SCRIPT (no code
        // execution), and scripts stay locked to the nonce. Verified in a
        // real browser: without it the console reports a CSP violation on
        // every page load.
        $directives = [
            "default-src 'self'",
            "img-src 'self' data:",
            "font-src 'self' data: https://fonts.bunny.net",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            'connect-src ' . implode(' ', $connect),
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ];

        if (app()->environment('local', 'testing')) {
            // Vite dev server: HMR needs websockets and injects inline script.
            $directives[] = "script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173";
        } else {
            // 'strict-dynamic' lets the nonce-carrying script load the Vite
            // bundle; without it the hashed bundle URL is not implicitly
            // trusted in some browsers.
            $directives[] = "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'";
            $directives[] = "upgrade-insecure-requests";
        }

        return implode('; ', $directives);
    }
}

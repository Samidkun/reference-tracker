<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        ];

        // HSTS only makes sense over TLS; sending it on http://localhost
        // would be ignored at best and is misleading at worst.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        $headers['Content-Security-Policy'] = $this->contentSecurityPolicy();

        foreach ($headers as $name => $value) {
            // Never clobber a header the app or a dependency already set.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $directives = [
            "default-src 'self'",
            "img-src 'self' data:",
            "font-src 'self' data: https://fonts.bunny.net",
            "style-src 'self' https://fonts.bunny.net",
            "connect-src 'self' https://api.crossref.org",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ];

        if (app()->environment('local', 'testing')) {
            // Vite dev server: HMR needs websockets, and the dev client is
            // injected inline.
            $directives[] = "script-src 'self' 'unsafe-inline' 'unsafe-eval' http://localhost:5173 http://[::1]:5173";
            $directives[] = "connect-src 'self' ws://localhost:5173 ws://[::1]:5173 https://api.crossref.org";
        } else {
            $directives[] = "script-src 'self'";
            $directives[] = "upgrade-insecure-requests";
        }

        return implode('; ', $directives);
    }
}

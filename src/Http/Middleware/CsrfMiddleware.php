<?php

namespace Luany\Framework\Http\Middleware;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;
use Luany\Framework\Security\CsrfToken;

/**
 * CsrfMiddleware
 *
 * Base middleware for CSRF protection.
 * Validates the '_token' field on state-changing requests (POST, PUT, PATCH, DELETE).
 * GET, HEAD, and OPTIONS requests are always allowed through.
 *
 * Applications can extend this to customize:
 *   - $except: URI patterns to exclude from CSRF checks (e.g. API webhooks)
 *   - tokenFromRequest(): how the token is extracted from the request
 *
 * Usage:
 *   // In your application kernel:
 *   protected array $middleware = [
 *       \App\Http\Middleware\VerifyCsrfToken::class,
 *   ];
 *
 *   // App\Http\Middleware\VerifyCsrfToken:
 *   class VerifyCsrfToken extends CsrfMiddleware
 *   {
 *       protected array $except = ['/api/webhook'];
 *   }
 */
class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * URI patterns to exclude from CSRF verification.
     * Override in application middleware.
     *
     * @var array<string>
     */
    protected array $except = [];

    public function __construct(private CsrfToken $csrf)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->shouldVerify($request) && !$this->tokensMatch($request)) {
            return Response::make('CSRF token mismatch.', 403);
        }

        return $next($request);
    }

    /**
     * Determine if the request should be verified.
     * Read-safe methods (GET, HEAD, OPTIONS) are skipped.
     */
    protected function shouldVerify(Request $request): bool
    {
        $method = strtoupper($request->method());

        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        return !$this->isExcluded($request);
    }

    /**
     * Check if the request URI matches any exclusion pattern.
     */
    protected function isExcluded(Request $request): bool
    {
        $uri = $request->uri();

        foreach ($this->except as $pattern) {
            if ($pattern === $uri) {
                return true;
            }

            // Simple wildcard: /api/* matches /api/webhook, /api/foo/bar
            if (str_ends_with($pattern, '*')) {
                $prefix = rtrim($pattern, '*');
                if (str_starts_with($uri, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Verify the submitted token matches the session token.
     */
    protected function tokensMatch(Request $request): bool
    {
        $token = $this->tokenFromRequest($request);

        if ($token === null) {
            return false;
        }

        return $this->csrf->validate($token);
    }

    /**
     * Extract the CSRF token from the request.
     * Checks POST body '_token' field, then X-CSRF-TOKEN header.
     * Override to customize extraction.
     */
    protected function tokenFromRequest(Request $request): ?string
    {
        // Check POST body '_token' field
        $token = $request->input('_token');
        if ($token !== null && $token !== '') {
            return $token;
        }

        // Check X-CSRF-TOKEN header (AJAX requests)
        $header = $request->header('X-CSRF-TOKEN');
        if ($header !== null && $header !== '') {
            return $header;
        }

        return null;
    }
}

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
 * Validates the token on state-changing requests (POST, PUT, PATCH, DELETE).
 * GET, HEAD, and OPTIONS requests are always allowed through.
 *
 * The token is read from (first non-empty string wins):
 *   1. form field '_token'          (CsrfToken::FIELD)
 *   2. legacy form field 'csrf_token' (CsrfToken::LEGACY_FIELD)
 *   3. header 'X-CSRF-TOKEN'          (CsrfToken::HEADER)
 * and compared with the session token ('_csrf_token') using hash_equals().
 * Non-string input (e.g. _token[]=x) is treated as missing → 403, never 500.
 *
 * Register it globally (it is resolved through the container, so the
 * CsrfToken dependency is injected; with no container it falls back to app('csrf')):
 *   protected array $middleware = [
 *       \Luany\Framework\Http\Middleware\CsrfMiddleware::class,
 *   ];
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

    /** HTTP status returned on token mismatch. */
    protected int $failureStatus = 403;

    public function __construct(private ?CsrfToken $csrf = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->shouldVerify($request) && !$this->tokensMatch($request)) {
            return $request->expectsJson()
                ? Response::json(['message' => 'CSRF token mismatch.'], $this->failureStatus)
                : Response::make('CSRF token mismatch.', $this->failureStatus);
        }

        return $next($request);
    }

    /**
     * The CsrfToken service (injected, or resolved from the container).
     */
    protected function csrf(): CsrfToken
    {
        if ($this->csrf === null) {
            $csrf = app('csrf');
            if (!$csrf instanceof CsrfToken) {
                throw new \RuntimeException('Container entry [csrf] must be a ' . CsrfToken::class . ' instance.');
            }
            $this->csrf = $csrf;
        }

        return $this->csrf;
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

        return $this->csrf()->validate($token);
    }

    /**
     * Extract the CSRF token from the request.
     * Checks the '_token' field, the legacy 'csrf_token' field, then the
     * X-CSRF-TOKEN header. Only non-empty strings are accepted.
     * Override to customize extraction.
     */
    protected function tokenFromRequest(Request $request): ?string
    {
        $candidates = [
            $request->input(CsrfToken::FIELD),
            $request->input(CsrfToken::LEGACY_FIELD),
            $request->header(CsrfToken::HEADER),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}

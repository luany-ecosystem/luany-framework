<?php

namespace Luany\Framework\Http\Middleware;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;
use Luany\Framework\Auth\AuthManager;

/**
 * Only logged-in users pass. Guests get 401 JSON (API) or a redirect to the
 * login page (config('auth.login'), default /login), remembering the page.
 *
 *   Route::middleware(Authenticate::class)->group(function () { … });
 */
class Authenticate implements MiddlewareInterface
{
    public function __construct(private ?AuthManager $auth = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $auth = $this->auth ?? auth();

        if ($auth->check()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return Response::json(['message' => 'Unauthenticated.'], 401);
        }

        if (strtoupper($request->method()) === 'GET') {
            $auth->setIntended($request->uri());
        }

        return Response::redirect($this->loginPath());
    }

    protected function loginPath(): string
    {
        return self::setting('auth.login', '/login');
    }

    /** A config value, or $default when there is no app or no value. */
    public static function setting(string $key, string $default): string
    {
        try {
            $value = config($key, $default);
        } catch (\Throwable) {
            return $default;
        }

        return is_string($value) && $value !== '' ? $value : $default;
    }
}

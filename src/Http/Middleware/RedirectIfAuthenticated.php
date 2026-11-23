<?php

namespace Luany\Framework\Http\Middleware;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;
use Luany\Framework\Auth\AuthManager;

/**
 * Only guests pass (login and register pages). Logged-in users are sent to
 * config('auth.home'), default /.
 */
class RedirectIfAuthenticated implements MiddlewareInterface
{
    public function __construct(private ?AuthManager $auth = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $auth = $this->auth ?? auth();

        if ($auth->guest()) {
            return $next($request);
        }

        return Response::redirect(Authenticate::setting('auth.home', '/'));
    }
}

<?php

namespace Luany\Framework\Http\Middleware;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;
use Luany\Framework\Auth\AuthManager;

/**
 * Only users whose `email_verified_at` is set pass. Others get 403 JSON or
 * a redirect to config('auth.verify_notice'), default /email/verify-notice.
 */
class EnsureEmailIsVerified implements MiddlewareInterface
{
    public function __construct(private ?AuthManager $auth = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = ($this->auth ?? auth())->user();
        $verified = $user !== null && !empty(method_exists($user, 'getAttribute') ? $user->getAttribute('email_verified_at') : ($user->email_verified_at ?? null));

        if ($verified) {
            return $next($request);
        }

        return $request->expectsJson()
            ? Response::json(['message' => 'Your email address is not verified.'], 403)
            : Response::redirect(Authenticate::setting('auth.verify_notice', '/email/verify-notice'));
    }
}

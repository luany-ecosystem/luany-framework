<?php

namespace Luany\Framework\Http\Middleware;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;
use Luany\Framework\Security\SignedUrl;

/**
 * Rejects URLs without a valid signature (403) and sends
 * Referrer-Policy: no-referrer so the signed link never leaks through the
 * Referer header of the page it opens.
 */
class ValidateSignature implements MiddlewareInterface
{
    public function __construct(private ?SignedUrl $signer = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $signer = $this->signer ?? app('url.signer');
        if (!$signer instanceof SignedUrl) {
            throw new \RuntimeException('Container entry [url.signer] must be a ' . SignedUrl::class . ' instance.');
        }

        $response = $signer->hasValidSignature($request)
            ? $next($request)
            : ($request->expectsJson()
                ? Response::json(['message' => 'Invalid or expired link.'], 403)
                : Response::make('Invalid or expired link.', 403));

        return $response->header('Referrer-Policy', 'no-referrer');
    }
}

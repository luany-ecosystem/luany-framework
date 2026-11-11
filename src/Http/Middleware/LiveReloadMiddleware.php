<?php

namespace Luany\Framework\Http\Middleware;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;

/**
 * LiveReloadMiddleware — browser live reload for `luany dev` (LDE).
 *
 * Active only when APP_ENV=development AND the request comes from the
 * loopback interface; otherwise it is a single early return.
 *
 *   1. Serves the LDE browser client at /__luany_dev/client.js (shipped with
 *      luany/cli; `luany dev` exports its path as LDE_CLIENT_PATH).
 *   2. Injects the client <script> before the last </body> of successful
 *      HTML responses.
 *
 * Register it first in the application Kernel so it sees the final response:
 *
 *   protected array $middleware = [
 *       LiveReloadMiddleware::class,
 *       CsrfMiddleware::class,
 *   ];
 */
class LiveReloadMiddleware implements MiddlewareInterface
{
    public const CLIENT_URI = '/__luany_dev/client.js';

    public function handle(Request $request, callable $next): Response
    {
        if (!$this->isDevelopment() || !$this->isLocalRequest($request)) {
            return $next($request);
        }

        if ($request->uri() === self::CLIENT_URI) {
            return $this->serveClient();
        }

        return $this->inject($next($request));
    }

    protected function isDevelopment(): bool
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV');
        return $env === 'development' || $env === 'local';
    }

    protected function isLocalRequest(Request $request): bool
    {
        $ip = (string) $request->server('REMOTE_ADDR', '');
        return $ip === '::1' || str_starts_with($ip, '127.');
    }

    private function serveClient(): Response
    {
        $candidates = [
            (string) (getenv('LDE_CLIENT_PATH') ?: ''),
            function_exists('base_path') ? base_path('vendor/luany/cli/src/Resources/dev/client.js') : '',
        ];

        foreach ($candidates as $path) {
            if ($path !== '' && is_file($path)) {
                return Response::make((string) file_get_contents($path), 200)
                    ->header('Content-Type', 'application/javascript; charset=UTF-8')
                    ->header('Cache-Control', 'no-store');
            }
        }

        return Response::make('// LDE client not found. Start the server with: luany dev', 404)
            ->header('Content-Type', 'application/javascript; charset=UTF-8');
    }

    private function inject(Response $response): Response
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return $response;
        }

        $contentType = '';
        foreach ($response->getHeaders() as $name => $value) {
            if (strcasecmp($name, 'Content-Type') === 0) {
                $contentType = $value;
            }
        }
        // Views return Response::make($html) without a Content-Type; anything
        // explicitly non-HTML (JSON, JS, CSS, files) is left alone.
        if ($contentType !== '' && stripos($contentType, 'text/html') === false) {
            return $response;
        }

        $body = $response->getBody();
        $pos  = strripos($body, '</body>');
        if ($pos === false) {
            return $response;
        }

        $port   = (int) (getenv('LDE_WS_PORT') ?: 35729);
        $script = '<script>window.__LDE_WS_PORT__ = ' . $port . ';</script>'
            . '<script src="' . self::CLIENT_URI . '"></script>';

        return $response->body(substr($body, 0, $pos) . $script . substr($body, $pos));
    }
}

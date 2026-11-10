<?php

namespace Luany\Framework\Http;

use Luany\Core\Exceptions\MethodNotAllowedException;
use Luany\Core\Exceptions\RouteNotFoundException;
use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;
use Luany\Core\Middleware\Pipeline;
use Luany\Core\Routing\Route;
use Luany\Framework\Application;
use Luany\Framework\Contracts\KernelInterface;
use Luany\Framework\Contracts\SessionInterface;
use Luany\Framework\Exceptions\Handler;
use Luany\Framework\Exceptions\HttpException;
use Luany\Framework\Exceptions\ValidationException;
use Luany\Framework\Security\CsrfToken;
use Luany\Framework\Session\FileSession;
use Luany\Framework\Log\FileLogger;
use Luany\Framework\Support\Config;
use Luany\Framework\Support\Translator;
use Luany\Lte\Engine;
use Psr\Log\LoggerInterface;

/**
 * Kernel
 *
 * The HTTP request lifecycle controller.
 *
 * Responsibilities:
 *   boot()     → register framework defaults (config, session, csrf, view,
 *                translator, logger) unless a service provider already bound
 *                them, wire the container resolver, load routes, boot providers
 *   handle()   → run global middleware pipeline → Route::handle()
 *   terminate()→ post-send cleanup
 *
 * Global middleware is applied to every request before routing.
 * Route-level middleware is applied inside Router::handle().
 *
 * Usage (public/index.php):
 *   $app    = new Application(__DIR__ . '/..');
 *   $kernel = $app->make(Kernel::class);
 *   $kernel->boot();
 *   $request  = Request::fromGlobals();
 *   $response = $kernel->handle($request);
 *   $response->send();
 *   $kernel->terminate($request, $response);
 */
class Kernel implements KernelInterface
{
    /**
     * Global middleware — applied to every request.
     * Override in your application's Kernel extension.
     *
     * Entries are class names (resolved through the container, so constructor
     * dependencies are injected) or MiddlewareInterface instances.
     *
     * @var array<int, class-string|MiddlewareInterface>
     */
    protected array $middleware = [];

    /**
     * Routes file path.
     * Defaults to routes/http.php relative to base path.
     */
    protected string $routesFile = 'routes/http.php';

    private bool $booted = false;

    public function __construct(private Application $app) {}

    // ── KernelInterface ───────────────────────────────────────────────────────

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->registerConfig();
        $this->registerSession();
        $this->registerCsrf();
        $this->registerLte();
        $this->registerTranslator();
        $this->registerLogger();
        $this->registerResolver();
        $this->loadRoutes();
        $this->app->bootProviders();

        $this->booted = true;
    }

    public function handle(Request $request): Response
    {
        if (!$this->booted) {
            $this->boot();
        }

        try {
            if (empty($this->middleware)) {
                return Route::handle($request);
            }

            return (new Pipeline())
                ->send($request)
                ->through($this->resolveMiddleware($this->middleware))
                ->then(function (Request $req) {
                    try {
                        return Route::handle($req);
                    } catch (\Throwable $e) {
                        return $this->handleException($e, $req);
                    }
                });
        } catch (\Throwable $e) {
            return $this->handleException($e, $request);
        }
    }

    /**
     * Convert an uncaught exception into a Response.
     *
     * Order:
     *   1. ValidationException → 422 JSON {message, errors} when the request
     *      expects JSON, otherwise a redirect (errors are already flashed).
     *   2. HTTP exceptions (abort(), 404, 405) for JSON clients → JSON with
     *      the exception's status and headers.
     *   3. Everything else, including HTML HTTP exceptions → the application
     *      Handler (report + render), so custom error pages apply to abort()
     *      as well as to unmatched routes.
     *   4. Without a bound Handler (or if it fails): HttpException → its
     *      status/headers, 405 with Allow, 404, otherwise 500.
     */
    protected function handleException(\Throwable $e, ?Request $request = null): Response
    {
        $wantsJson = $request !== null && $request->expectsJson();

        if ($e instanceof ValidationException) {
            if ($wantsJson) {
                return Response::json([
                    'message' => $e->getMessage(),
                    'errors'  => $e->getErrors(),
                ], 422);
            }
            return Response::redirect($e->getRedirectTo());
        }

        $http = $this->httpExceptionParts($e);

        // Handler::render() has no access to the request, so JSON clients
        // keep the Kernel's JSON shape for HTTP errors.
        if ($http !== null && $wantsJson) {
            return $this->httpResponse($http[0], $http[1], $http[2], true);
        }

        try {
            $handler = $this->app->make(Handler::class);
        } catch (\Throwable) {
            $handler = null;
        }

        if ($handler instanceof Handler) {
            try {
                $handler->report($e);
            } catch (\Throwable) {}

            try {
                return $handler->render($e);
            } catch (\Throwable) {
                // fall through to the built-in responses
            }
        }

        if ($http !== null) {
            return $this->httpResponse($http[0], $http[1], $http[2], $wantsJson);
        }

        if (!$handler instanceof Handler) {
            $this->logException($e);
        }

        return $wantsJson
            ? Response::json(['message' => 'Server Error'], 500)
            : Response::serverError();
    }

    /**
     * Status, message and headers for exceptions that map to an HTTP error
     * (abort(), unmatched route, wrong method); null for anything else.
     *
     * @return array{0: int, 1: string, 2: array<string, string>}|null
     */
    private function httpExceptionParts(\Throwable $e): ?array
    {
        return match (true) {
            $e instanceof HttpException             => [$e->getStatusCode(), $e->getMessage(), $e->getHeaders()],
            // RFC 9110 §15.5.6 requires an Allow header on 405.
            $e instanceof MethodNotAllowedException => [405, 'Method Not Allowed', ['Allow' => $e->getAllowHeaderValue()]],
            $e instanceof RouteNotFoundException    => [404, 'Not Found', []],
            default                                 => null,
        };
    }

    /**
     * Log an unhandled exception through the bound PSR-3 logger.
     * Never throws — falls back to error_log().
     */
    protected function logException(\Throwable $e): void
    {
        try {
            $logger = $this->app->make('log');
            if ($logger instanceof LoggerInterface) {
                $logger->error(sprintf('Unhandled %s: %s', get_class($e), $e->getMessage()), ['exception' => $e]);
                return;
            }
        } catch (\Throwable) {
        }

        error_log(sprintf('[%s] %s in %s on line %d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    }

    /**
     * Build a plain HTTP error response. The message is HTML-escaped.
     *
     * @param array<string, string> $headers
     */
    private function httpResponse(int $status, string $message, array $headers, bool $json): Response
    {
        if ($json) {
            return Response::json(['message' => $message], $status, $headers);
        }

        return Response::make(
            htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'] + $headers
        );
    }

    public function terminate(Request $request, Response $response): void
    {
        // Hook for post-send tasks: session save, logging, metrics, etc.
        // Override in application kernel to add behaviour.
    }

    // ── Private ───────────────────────────────────────────────────────────────────────

    /**
     * Resolve a class through the container (autowiring constructor dependencies).
     * Used as the luany/core resolver for controllers and middleware.
     */
    public function resolveClass(string $class): object
    {
        $object = $this->app->make($class);

        if (!is_object($object)) {
            throw new \RuntimeException("Container entry [{$class}] did not resolve to an object.");
        }

        return $object;
    }

    /**
     * Register the container as luany/core's class resolver so controllers and
     * class-name middleware get their constructor dependencies injected.
     *
     * The hook exists from luany/core 1.1; on 1.0 this is a no-op (global
     * middleware is still resolved through the container by the Kernel).
     */
    private function registerResolver(): void
    {
        $resolver = fn (string $class): object => $this->resolveClass($class);

        // Guarded so the framework stays installable with luany/core 1.0.
        self::callIfExists(Pipeline::class, 'setDefaultResolver', $resolver);
        self::callIfExists(Route::router(), 'setResolver', $resolver);
    }

    /**
     * Call $target->$method(...$args) only when the method exists
     * (optional hooks added in newer luany/core releases).
     */
    private static function callIfExists(object|string $target, string $method, mixed ...$args): void
    {
        if (!method_exists($target, $method)) {
            return;
        }

        $callable = [$target, $method];
        if (is_callable($callable)) {
            $callable(...$args);
        }
    }

    /**
     * Instantiate global middleware through the container, so constructor
     * injection works regardless of the installed luany/core version.
     *
     * @param  array<int, class-string|MiddlewareInterface> $middleware
     * @return array<int, MiddlewareInterface>
     */
    private function resolveMiddleware(array $middleware): array
    {
        $resolved = [];

        foreach ($middleware as $entry) {
            $instance = is_string($entry) ? $this->resolveClass($entry) : $entry;

            if (!$instance instanceof MiddlewareInterface) {
                $name = is_string($entry) ? $entry : get_debug_type($entry);
                throw new \RuntimeException("Middleware [{$name}] must implement MiddlewareInterface");
            }

            $resolved[] = $instance;
        }

        return $resolved;
    }

    /**
     * Register a framework default under a container key and a class alias.
     *
     * Bindings made by service providers (which register before the Kernel
     * boots) always win:
     *   - neither bound   → $key gets the default, $class aliases $key
     *   - only $key bound → $class aliases the provider's $key
     *   - only $class bound → $key aliases the provider's $class
     *   - both bound      → nothing is touched
     *
     * @param callable(Application): mixed $factory
     */
    protected function registerDefault(string $key, ?string $class, callable $factory): void
    {
        $app      = $this->app;
        $keyBound = $app->bound($key);

        if ($class === null) {
            if (!$keyBound) {
                $app->singleton($key, $factory);
            }
            return;
        }

        $classBound = $app->bound($class);

        if (!$keyBound) {
            $app->singleton($key, $classBound ? fn (Application $app) => $app->make($class) : $factory);
        }

        if (!$classBound) {
            $app->singleton($class, fn (Application $app) => $app->make($key));
        }
    }

    private function registerConfig(): void
    {
        $this->registerDefault('config', Config::class, function (Application $app) {
            return new Config($app->configPath());
        });
    }

    private function registerSession(): void
    {
        $this->registerDefault('session', SessionInterface::class, function (Application $app) {
            // Optional config/session.php — keys: save_path, name, lifetime (seconds),
            // path, domain, secure (null = auto on HTTPS), httponly, samesite,
            // strict_mode, only_cookies. See FileSession::DEFAULTS.
            $config  = $app->make('config');
            $options = $config instanceof Config ? $config->get('session', []) : [];
            $options = is_array($options) ? $options : [];

            $savePath = $options['save_path'] ?? $app->storagePath('sessions');

            $session = new FileSession(is_string($savePath) ? $savePath : $app->storagePath('sessions'), $options);
            $session->start();
            return $session;
        });
    }

    private function registerCsrf(): void
    {
        $this->registerDefault('csrf', CsrfToken::class, function (Application $app) {
            return new CsrfToken($app->make('session'));
        });
    }

    private function registerLte(): void
    {
        $this->registerDefault('view', Engine::class, function () {
            return new Engine(
                viewsPath:  $this->app->viewsPath(),
                cachePath:  $this->app->cachePath('views'),
                autoReload: (bool) env('APP_DEBUG', false),
            );
        });

        $this->configureLteRuntime();

        // Connect the bound view engine to Route::view()
        Route::setViewRenderer(function (string $name, array $data) {
            return app('view')->render($name, $data);
        });
    }

    /**
     * Whether the application runs in debug mode: config('app.debug'),
     * falling back to APP_DEBUG.
     */
    protected function isDebug(): bool
    {
        try {
            $config = $this->app->make('config');
            if ($config instanceof Config && $config->has('app.debug')) {
                return (bool) $config->get('app.debug');
            }
        } catch (\Throwable) {
        }

        return (bool) env('APP_DEBUG', false);
    }

    /**
     * Tell luany/lte's runtime whether debug directives (@dump/@dd) are active.
     * Luany\Lte\Runtime exists from luany/lte 1.1; on 1.0 this is a no-op.
     */
    private function configureLteRuntime(): void
    {
        if (class_exists(\Luany\Lte\Runtime::class)) {
            \Luany\Lte\Runtime::setDebug($this->isDebug());
        }
    }

    /**
     * Default translator — lang/{locale}.php files.
     *
     * Settings (config/app.php first, then .env):
     *   locale            app.locale            | APP_LOCALE            (default 'en')
     *   fallback_locale   app.fallback_locale   | APP_FALLBACK_LOCALE   (default 'en')
     *   supported_locales app.supported_locales | APP_SUPPORTED_LOCALES (comma list,
     *                                              default [locale, fallback])
     */
    private function registerTranslator(): void
    {
        $this->registerDefault('translator', Translator::class, function (Application $app) {
            $config = $app->make('config');
            $get = fn (string $key, mixed $default) => $config instanceof Config ? $config->get($key, $default) : $default;

            $locale   = (string) $get('app.locale', env('APP_LOCALE', 'en'));
            $fallback = (string) $get('app.fallback_locale', env('APP_FALLBACK_LOCALE', 'en'));

            $supported = $get('app.supported_locales', env('APP_SUPPORTED_LOCALES'));
            if (is_string($supported)) {
                $supported = array_map('trim', explode(',', $supported));
            }
            if (!is_array($supported) || $supported === []) {
                $supported = [$locale, $fallback];
            }
            $supported = array_values(array_unique(array_filter(array_map('strval', $supported), fn ($l) => $l !== '')));

            return new Translator($app->langPath(), $locale, $fallback, $supported);
        });
    }

    /**
     * Default PSR-3 logger — daily files in storage/logs.
     *
     * Settings (config/logging.php first, then .env):
     *   path    logging.path    (default storage/logs)
     *   channel logging.channel (default 'luany')
     *   level   logging.level   | LOG_LEVEL (default 'debug')
     *   daily   logging.daily   (default true)
     */
    private function registerLogger(): void
    {
        $this->registerDefault('log', LoggerInterface::class, function (Application $app) {
            $config = $app->make('config');
            $get = fn (string $key, mixed $default) => $config instanceof Config ? $config->get($key, $default) : $default;

            return new FileLogger(
                (string) $get('logging.path', $app->storagePath('logs')),
                (string) $get('logging.channel', 'luany'),
                strtolower((string) $get('logging.level', env('LOG_LEVEL', 'debug'))),
                (bool) $get('logging.daily', true),
            );
        });
    }

    /**
     * Load route files.
     *
     * Strategy — auto-discovery with deterministic order:
     *
     *   1. Load routes/http.php first (the "core" routes file — backward compatible).
     *      This file defines global routes: home, locale, error handling, etc.
     *
     *   2. Load every other *.php file in the routes/ directory, sorted alphabetically.
     *      Files are skipped if already loaded (http.php exclusion prevents double-loading).
     *
     * This means `luany make:feature Product` can generate routes/products.php
     * instead of appending to http.php — the Kernel picks it up automatically.
     *
     * Example routes/ directory in a scaled application:
     *   routes/
     *     http.php          ← core / global routes
     *     api.php           ← API routes
     *     auth.php          ← authentication routes
     *     products.php      ← generated by: luany make:feature Product
     *     users.php         ← generated by: luany make:feature User
     *
     * Override $routesFile in your application Kernel to change the primary file name.
     * Set $routesFile to '' to skip the primary file and rely solely on auto-discovery.
     */
    private function loadRoutes(): void
    {
        $routesDir = $this->app->routesPath();

        if (!is_dir($routesDir)) {
            return;
        }

        $loaded = [];

        // 1. Load the primary routes file first (default: routes/http.php)
        if ($this->routesFile !== '') {
            $primary = $routesDir . DIRECTORY_SEPARATOR . basename($this->routesFile);
            if (file_exists($primary)) {
                require $primary;
                $loaded[realpath($primary)] = true;
            }
        }

        // 2. Auto-discover and load remaining *.php files alphabetically
        $files = glob($routesDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files); // deterministic order across all platforms

        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false && !isset($loaded[$real])) {
                require $file;
                $loaded[$real] = true;
            }
        }
    }
}
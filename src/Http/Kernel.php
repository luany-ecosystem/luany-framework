<?php

namespace Luany\Framework\Http;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Middleware\MiddlewareInterface;
use Luany\Core\Middleware\Pipeline;
use Luany\Core\Routing\Route;
use Luany\Framework\Application;
use Luany\Framework\Contracts\KernelInterface;
use Luany\Framework\Contracts\SessionInterface;
use Luany\Framework\Security\CsrfToken;
use Luany\Framework\Session\FileSession;
use Luany\Framework\Support\Config;
use Luany\Lte\Engine;

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
                        return $this->handleException($e);
                    }
                });
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    private function handleException(\Throwable $e): Response
    {
        // validate() throws ValidationException on failure:
        // errors are already flashed to session — just redirect back.
        if ($e instanceof \Luany\Framework\Exceptions\ValidationException) {
            return Response::redirect($e->getRedirectTo());
        }

        // abort() throws HttpException — convert directly to the matching response.
        if ($e instanceof \Luany\Framework\Exceptions\HttpException) {
            return Response::make($e->getMessage(), $e->getStatusCode());
        }

        // Router throws MethodNotAllowedException when URI matches but method doesn't.
        // RFC 7231 §6.5.5 requires a 405 response with an Allow header.
        if ($e instanceof \Luany\Core\Exceptions\MethodNotAllowedException) {
            return Response::make('Method Not Allowed', 405)
                ->header('Allow', $e->getAllowHeaderValue());
        }

        try {
            $handler = $this->app->make(\Luany\Framework\Exceptions\Handler::class);
            try {
                $handler->report($e);
            } catch (\Throwable) {}
            return $handler->render($e);
        } catch (\Throwable) {
            if ($e instanceof \Luany\Core\Exceptions\RouteNotFoundException) {
                return Response::notFound();
            }
            return Response::serverError();
        }
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
        $setDefault = [Pipeline::class, 'setDefaultResolver'];
        if (method_exists(Pipeline::class, 'setDefaultResolver') && is_callable($setDefault)) {
            $setDefault($resolver);
        }

        $router    = Route::router();
        $setRouter = [$router, 'setResolver'];
        if (method_exists($router, 'setResolver') && is_callable($setRouter)) {
            $setRouter($resolver);
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
            $session = new FileSession($app->storagePath('sessions'));
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

        // Connect the bound view engine to Route::view()
        Route::setViewRenderer(function (string $name, array $data) {
            return app('view')->render($name, $data);
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
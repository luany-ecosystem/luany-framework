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
use Luany\Framework\Container\MissingModelException;
use Luany\Framework\Contracts\KernelInterface;
use Luany\Framework\Contracts\SessionInterface;
use Luany\Framework\Exceptions\Handler;
use Luany\Framework\Exceptions\HttpException;
use Luany\Framework\Exceptions\ValidationException;
use Luany\Framework\Security\CsrfToken;
use Luany\Framework\Session\FileSession;
use Luany\Framework\Log\FileLogger;
use Luany\Framework\Support\AfterResponse;
use Luany\Framework\Support\Config;
use Luany\Framework\Support\Translator;
use Luany\Framework\Validation\DatabasePresenceVerifier;
use Luany\Framework\Validation\Validator;
use Luany\Lte\Engine;
use Luany\Framework\Auth\AuthManager;
use Luany\Framework\Auth\Hasher;
use Luany\Framework\Auth\DatabaseTokenRepository;
use Luany\Framework\Auth\EmailVerification;
use Luany\Framework\Auth\ModelUserProvider;
use Luany\Framework\Auth\PasswordBroker;
use Luany\Framework\Http\Middleware\Authenticate;
use Luany\Framework\Security\SignedUrl;
use Luany\Core\RateLimit\FileRateLimiter;
use Luany\Framework\Mail\Address;
use Luany\Framework\Mail\MailException;
use Luany\Framework\Mail\Mailer;
use Luany\Framework\Mail\Transport\ArrayTransport;
use Luany\Framework\Mail\Transport\LogTransport;
use Luany\Framework\Mail\Transport\SmtpTransport;
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
     * dependencies are injected) or instances of Luany's MiddlewareInterface
     * or PSR-15's Psr\Http\Server\MiddlewareInterface — like route
     * middleware. PSR-15 middleware requires luany/core 1.3+.
     *
     * @var array<int, class-string|object>
     */
    protected array $middleware = [];

    /**
     * Routes file path.
     * Defaults to routes/http.php relative to base path.
     */
    protected string $routesFile = 'routes/http.php';

    /**
     * Route cache file, relative to the base path. When it exists, routes are
     * loaded from it instead of routes/*.php. Set to '' to disable.
     */
    protected string $routeCacheFile = 'storage/cache/routes.php';

    private bool $booted = false;

    /** PSR-15 middleware interface and luany/core's adapter (core 1.3+), referenced by name. */
    private const PSR15_MIDDLEWARE = 'Psr\\Http\\Server\\MiddlewareInterface';
    private const PSR15_ADAPTER    = 'Luany\\Core\\Psr\\Psr15Middleware';

    public function __construct(private Application $app) {}

    // ── KernelInterface ───────────────────────────────────────────────────────

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->registerConfig();
        $this->configureTrustedProxies();
        $this->registerSession();
        $this->registerCsrf();
        $this->registerAuth();
        $this->registerLte();
        $this->registerTranslator();
        $this->registerLogger();
        $this->registerMail();
        $this->registerResolver();
        $this->loadRoutes();
        $this->app->bootProviders();
        $this->configurePresenceVerifier(); // after providers: they may bind 'db' or set their own

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
     *   2. HTTP exceptions (abort(), 404, 405, luany/database's
     *      ModelNotFoundException → 404) for JSON clients → JSON with the
     *      exception's status and headers.
     *   3. Everything else, including HTML HTTP exceptions → the application
     *      Handler: report(), then render() — or renderJson() when the request
     *      expects JSON — so custom error pages apply to abort() as well as
     *      to unmatched routes.
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
                return $wantsJson ? $handler->renderJson($e) : $handler->render($e);
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
            is_a($e, Handler::MODEL_NOT_FOUND)      => [404, 'Not Found', []],
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

        // luany/core 1.2+: resolve action arguments through the container —
        // services by type, route parameters by name, the rest by position.
        self::callIfExists(Route::router(), 'setInvoker', $this->actionInvoker());
    }

    /**
     * @return \Closure(callable, Request, array<string, mixed>): mixed
     */
    private function actionInvoker(): \Closure
    {
        return function (callable $action, Request $request, array $params): mixed {
            try {
                return $this->app->call($action, array_merge([$request], $params));
            } catch (MissingModelException $e) {
                // A route-model binding found nothing (e.g. User::find($id) === null).
                throw new HttpException(404, 'Not Found', $e);
            }
        };
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
     * PSR-15 middleware is wrapped in luany/core's Psr15Middleware adapter,
     * as the route pipeline does.
     *
     * @param  array<int, class-string|object> $middleware
     * @return array<int, MiddlewareInterface>
     */
    private function resolveMiddleware(array $middleware): array
    {
        $resolved = [];

        foreach ($middleware as $entry) {
            $instance = is_string($entry) ? $this->resolveClass($entry) : $entry;
            $name     = is_string($entry) ? $entry : get_debug_type($entry);

            if (is_object($instance) && !$instance instanceof MiddlewareInterface && is_a($instance, self::PSR15_MIDDLEWARE)) {
                if (!class_exists(self::PSR15_ADAPTER)) {
                    throw new \RuntimeException("Middleware [{$name}] is PSR-15 middleware, which requires luany/core 1.3 or later");
                }
                $adapter  = self::PSR15_ADAPTER;
                $instance = new $adapter($instance);
            }

            if (!$instance instanceof MiddlewareInterface) {
                throw new \RuntimeException(
                    "Middleware [{$name}] must implement Luany\\Core\\Middleware\\MiddlewareInterface or PSR-15's Psr\\Http\\Server\\MiddlewareInterface"
                );
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

    /**
     * Proxies whose X-Forwarded-For is trusted for $request->ip() (rate limits,
     * logs): config('app.trusted_proxies') or TRUSTED_PROXIES — IPs or CIDR
     * ranges, comma-separated. Empty (default): the connecting address is used.
     */
    private function configureTrustedProxies(): void
    {
        $config  = $this->app->make('config');
        $proxies = $config instanceof Config ? $config->get('app.trusted_proxies') : null;
        $proxies ??= env('TRUSTED_PROXIES', '');

        if (is_string($proxies)) {
            $proxies = explode(',', $proxies);
        }
        if (!is_array($proxies)) {
            return;
        }

        $proxies = array_values(array_filter(array_map(fn ($p): string => trim((string) $p), $proxies), fn (string $p): bool => $p !== ''));
        if ($proxies !== []) {
            Request::setTrustedProxies($proxies);
        }
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

    /**
     * Session authentication — auth(), app('hash').
     *
     * Optional config/auth.php: model (default App\Models\User), login
     * (path guests are sent to, default /login), home (default /).
     */
    private function registerAuth(): void
    {
        $this->registerDefault('hash', Hasher::class, fn () => new Hasher());

        $this->registerDefault('auth', AuthManager::class, function (Application $app) {
            $config = $app->make('config');
            $model  = $config instanceof Config ? $config->get('auth.model', 'App\\Models\\User') : 'App\\Models\\User';

            $session = $app->make('session');
            $hasher  = $app->make('hash');
            if (!$session instanceof SessionInterface || !$hasher instanceof Hasher) {
                throw new \RuntimeException('auth needs the [session] and [hash] services.');
            }

            $auth = new AuthManager($session, new ModelUserProvider(is_string($model) ? $model : 'App\\Models\\User'), $hasher);
            // A new session gets a new CSRF token.
            $auth->onSessionChange(function () use ($app): void {
                $csrf = $app->make('csrf');
                if ($csrf instanceof CsrfToken) {
                    $csrf->regenerate();
                }
            });

            return $auth;
        });

        // Signed URLs with the APP_KEY (resolved on first use).
        $this->registerDefault('url.signer', SignedUrl::class, function (Application $app) {
            $config = $app->make('config');
            $key = $config instanceof Config ? $config->get('app.key') : null;

            return new SignedUrl((string) ($key ?? env('APP_KEY', '')));
        });

        $this->registerDefault('auth.verification', EmailVerification::class, function (Application $app) {
            $signer = $app->make('url.signer');
            if (!$signer instanceof SignedUrl) {
                throw new \RuntimeException('Container entry [url.signer] must be a ' . SignedUrl::class . ' instance.');
            }

            return new EmailVerification($signer, Authenticate::setting('auth.verify_path', '/email/verify'));
        });

        // Password reset tokens in the password_reset_tokens table of the 'db' connection.
        $this->registerDefault('auth.passwords', PasswordBroker::class, function (Application $app) {
            $auth   = $app->make('auth');
            $hasher = $app->make('hash');
            if (!$auth instanceof AuthManager || !$hasher instanceof Hasher) {
                throw new \RuntimeException('auth.passwords needs the [auth] and [hash] services.');
            }

            return new PasswordBroker(
                $auth->provider(),
                $hasher,
                new DatabaseTokenRepository($this->pdo()),
                new FileRateLimiter($app->storagePath('rate-limits')),
                defer: static fn (callable $job) => AfterResponse::run($job),
            );
        });
    }

    /** The application's PDO ('db', 'connection' or PDO binding). */
    private function pdo(): \PDO
    {
        foreach (['db', 'connection', \PDO::class] as $id) {
            if (!$this->app->has($id)) {
                continue;
            }
            $entry = $this->app->make($id);
            if ($entry instanceof \PDO) {
                return $entry;
            }
            if (is_object($entry) && method_exists($entry, 'getPdo')) {
                $pdo = $entry->getPdo();
                if ($pdo instanceof \PDO) {
                    return $pdo;
                }
            }
        }

        throw new \LogicException('No PDO connection: bind [db] to use password reset tokens.');
    }

    /**
     * app('mailer') / mailer(). Settings: config/mail.php, then .env —
     *   default  MAIL_MAILER        log (default) | smtp | array
     *   from     MAIL_FROM_ADDRESS, MAIL_FROM_NAME
     *   mailers.smtp: host MAIL_HOST, port MAIL_PORT (587), username
     *   MAIL_USERNAME, password MAIL_PASSWORD, encryption MAIL_ENCRYPTION (tls)
     */
    private function registerMail(): void
    {
        $this->registerDefault('mailer', Mailer::class, function (Application $app) {
            $config = $app->make('config');
            $get = function (string $key, string $env, mixed $default) use ($config): mixed {
                $value = $config instanceof Config ? $config->get($key) : null;

                return $value ?? env($env, $default);
            };

            $mailer = (string) $get('mail.default', 'MAIL_MAILER', 'log');
            $transport = match ($mailer) {
                'log'   => new LogTransport($this->logger($app)),
                'array' => new ArrayTransport(),
                'smtp'  => new SmtpTransport(
                    host:       (string) $get('mail.mailers.smtp.host', 'MAIL_HOST', ''),
                    port:       (int) $get('mail.mailers.smtp.port', 'MAIL_PORT', 587),
                    username:   (string) $get('mail.mailers.smtp.username', 'MAIL_USERNAME', ''),
                    password:   (string) $get('mail.mailers.smtp.password', 'MAIL_PASSWORD', ''),
                    encryption: (string) $get('mail.mailers.smtp.encryption', 'MAIL_ENCRYPTION', 'tls'),
                ),
                default => throw new MailException('LUANY-MAIL-005', "Unknown mailer [{$mailer}]: use log, smtp or array."),
            };

            $address = (string) $get('mail.from.address', 'MAIL_FROM_ADDRESS', '');
            $from = $address !== '' ? new Address($address, (string) $get('mail.from.name', 'MAIL_FROM_NAME', '')) : null;

            return new Mailer(
                $transport,
                $from,
                fn (string $view, array $data): string => $this->viewEngine($app)->render($view, $data),
                fn (string $view): bool => $this->viewEngine($app)->findView($view) !== null,
            );
        });
    }

    private function logger(Application $app): LoggerInterface
    {
        $logger = $app->make('log');
        if (!$logger instanceof LoggerInterface) {
            throw new \RuntimeException('Container entry [log] must implement ' . LoggerInterface::class . '.');
        }

        return $logger;
    }

    private function viewEngine(Application $app): Engine
    {
        $engine = $app->make('view');
        if (!$engine instanceof Engine) {
            throw new \RuntimeException('Container entry [view] must be a ' . Engine::class . ' instance.');
        }

        return $engine;
    }

    private function registerLte(): void
    {
        $this->registerDefault('view', Engine::class, function () {
            $engine = new Engine(
                viewsPath:  $this->app->viewsPath(),
                cachePath:  $this->app->cachePath('views'),
                autoReload: (bool) env('APP_DEBUG', false),
            );

            return $engine->setSyntax($this->templateSyntax());
        });

        $this->configureLteRuntime();
        $this->configureTimezone();

        // Connect the bound view engine to Route::view()
        Route::setViewRenderer(function (string $name, array $data) {
            return app('view')->render($name, $data);
        });
    }

    /**
     * Whether the application runs in debug mode: config('app.debug'),
     * falling back to APP_DEBUG.
     */
    /**
     * Template syntax for LTE: config('app.syntax'), falling back to
     * LUANY_SYNTAX, then "en". "pt" adds the Portuguese directive aliases.
     */
    protected function templateSyntax(): string
    {
        try {
            $config = $this->app->make('config');
            if ($config instanceof Config && $config->has('app.syntax')) {
                return (string) $config->get('app.syntax');
            }
        } catch (\Throwable) {
        }

        return (string) env('LUANY_SYNTAX', 'en');
    }

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
    /**
     * Wire the unique / exists validation rules to the application database:
     * the 'db' binding (PDO, as registered by the skeleton's
     * DatabaseServiceProvider), or an object exposing getPdo() bound as
     * 'connection'. Resolved lazily — no connection is opened until a rule
     * needs it. An explicitly configured verifier is left untouched.
     */
    private function configurePresenceVerifier(): void
    {
        if (Validator::getPresenceVerifier() !== null) {
            return;
        }

        if (!$this->app->has('db') && !$this->app->has('connection') && !$this->app->has(\PDO::class)) {
            return;
        }

        Validator::setPresenceVerifier(new DatabasePresenceVerifier(function (): \PDO {
            foreach (['db', 'connection', \PDO::class] as $id) {
                if (!$this->app->has($id)) {
                    continue;
                }
                $entry = $this->app->make($id);
                if ($entry instanceof \PDO) {
                    return $entry;
                }
                if (is_object($entry) && method_exists($entry, 'getPdo')) {
                    $pdo = $entry->getPdo();
                    if ($pdo instanceof \PDO) {
                        return $pdo;
                    }
                }
            }
            throw new \LogicException('No PDO connection available for the unique / exists validation rules.');
        }));
    }

    /**
     * Default timezone from config('app.timezone'), then APP_TIMEZONE
     * (left untouched when neither is set).
     */
    private function configureTimezone(): void
    {
        $timezone = null;
        try {
            $timezone = config('app.timezone');
        } catch (\Throwable) {
        }
        $timezone ??= env('APP_TIMEZONE');

        if (is_string($timezone) && $timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            date_default_timezone_set($timezone);
        }
    }

    private function configureLteRuntime(): void
    {
        if (class_exists(\Luany\Lte\Runtime::class)) {
            \Luany\Lte\Runtime::setDebug($this->isDebug());
            // @auth / @guest (and @autenticado / @visitante) follow auth()->check().
            $app = $this->app;
            \Luany\Lte\Runtime::setAuthResolver(static function () use ($app): bool {
                $auth = $app->make('auth');

                return $auth instanceof AuthManager && $auth->check();
            });
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
     * Absolute path of the route cache file, or null when disabled.
     *
     * Generate it after loading the route files, e.g. in a deploy script:
     *   Route::cache($kernel->routeCachePath());
     * Delete it (Route::clearCache()) whenever routes change.
     */
    public function routeCachePath(): ?string
    {
        return $this->routeCacheFile === '' ? null : $this->app->basePath($this->routeCacheFile);
    }

    /**
     * Load the routes: from the route cache when the file exists, otherwise
     * from the route files.
     *
     * Cached routes contain controller / Route::view() actions and
     * Route::model() bindings only — closure Route::bind() resolvers are
     * not cached, so register those in a service provider.
     */
    private function loadRoutes(): void
    {
        $cache = $this->routeCachePath();

        if ($cache !== null && is_file($cache) && Route::loadCache($cache)) {
            return;
        }

        $this->loadRouteFiles();
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
    private function loadRouteFiles(): void
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
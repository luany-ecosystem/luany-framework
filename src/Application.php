<?php

namespace Luany\Framework;

use Luany\Framework\Container\CircularDependencyException;
use Luany\Framework\Container\ContainerException;
use Luany\Framework\Container\NotFoundException;
use Luany\Framework\Contracts\ApplicationInterface;
use Luany\Framework\Contracts\ServiceProviderInterface;
use Psr\Container\ContainerInterface;

/**
 * Application
 *
 * The Luany DI container and service registry.
 * Implements ApplicationInterface with bind, singleton, instance and make.
 *
 * Also acts as the global application instance — accessible via app().
 *
 * Service Provider lifecycle:
 *   1. $app->register(new FooServiceProvider()) — calls provider->register()
 *   2. $app->bootProviders()                    — calls provider->boot() on all registered
 *   (Kernel calls bootProviders() automatically during boot())
 *
 * Usage:
 *   $app = new Application(__DIR__);
 *   $app->register(new DatabaseServiceProvider());
 *   $app->singleton('cache', fn() => new Cache(env('CACHE_DRIVER')));
 *   $db = $app->make('db');
 *
 * The Application is itself bound in the container:
 *   $app->make(Application::class) === $app
 *
 * Autowiring:
 *   Unbound concrete classes are built via reflection. Constructor
 *   parameters are resolved from their class/interface type; scalar
 *   parameters use their default value, nullable parameters fall back to
 *   null. Interfaces are mapped to implementations with bind():
 *
 *   $app->bind(LoggerInterface::class, FileLogger::class);
 *   $app->singleton(Mailer::class);              // shared, autowired
 *   $app->make(ReportService::class, ['year' => 2026]);
 *
 * PSR-11: get()/has() implement Psr\Container\ContainerInterface.
 */
class Application implements ApplicationInterface, ContainerInterface
{
    /** @var array<string, \Closure(self, array<string, mixed>): mixed> Transient factory bindings */
    private array $bindings = [];

    /** @var array<string, \Closure(self, array<string, mixed>): mixed> Shared singleton factories */
    private array $singletonFactories = [];

    /** @var array<string, mixed> Resolved singleton instances */
    private array $instances = [];

    /** @var array<int, \Luany\Framework\Contracts\ServiceProviderInterface> Registered service providers */
    private array $providers = [];

    /** Whether bootProviders() has been called */
    private bool $booted = false;

    /** @var array<string, true> Entries currently being resolved (circular dependency guard) */
    private array $resolving = [];

    /** Absolute path to the application root */
    private string $basePath;

    /** Global application instance */
    protected static ?self $instance = null;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');

        // Register self in the container
        $this->instance(self::class, $this);
        $this->instance(ApplicationInterface::class, $this);

        static::$instance = $this;
    }

    // ── Static accessor ────────────────────────────────────────────────────────

    /**
     * Get the global application instance.
     */
    public static function getInstance(): static
    {
        if (static::$instance === null) {
            throw new \RuntimeException('Application has not been instantiated.');
        }
        return static::$instance; // @phpstan-ignore return.type
    }

    // ── Service Providers ─────────────────────────────────────────────────────

    /**
     * Register a service provider.
     * Immediately calls register() on the provider.
     * boot() is deferred until bootProviders() is called.
     */
    public function register(ServiceProviderInterface $provider): void
    {
        $provider->register($this);
        $this->providers[] = $provider;
    }

    /**
     * Boot all registered service providers.
     * Called by Kernel::boot() — do not call manually.
     * Idempotent — safe to call multiple times.
     */
    public function bootProviders(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->providers as $provider) {
            $provider->boot($this);
        }

        $this->booted = true;
    }

    /**
     * Get all registered service providers.
     */
    /** @return array<int, \Luany\Framework\Contracts\ServiceProviderInterface> */
    public function getProviders(): array
    {
        return $this->providers;
    }

    // ── Container ─────────────────────────────────────────────────────────────

    /**
     * Register a transient binding.
     *
     * $concrete may be a factory callable (receives the Application),
     * a class name (built via autowiring — e.g. interface → implementation),
     * or null to autowire $abstract itself.
     */
    public function bind(string $abstract, callable|string|null $concrete = null): void
    {
        $this->bindings[$abstract] = $this->normalizeConcrete($abstract, $concrete);
    }

    /**
     * Register a shared binding — built once, then cached.
     * Accepts the same $concrete forms as bind().
     */
    public function singleton(string $abstract, callable|string|null $concrete = null): void
    {
        unset($this->instances[$abstract]);
        $this->singletonFactories[$abstract] = $this->normalizeConcrete($abstract, $concrete);
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * Resolve an entry from the container.
     *
     * @param array<string, mixed> $parameters Named constructor arguments used
     *                                         when autowiring (override resolution)
     *
     * @throws NotFoundException           when nothing is bound and the class does not exist
     * @throws CircularDependencyException when the entry depends on itself
     * @throws ContainerException          when the entry cannot be built
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        // Pre-built instance
        if (array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        if (isset($this->resolving[$abstract])) {
            $chain = implode(' -> ', array_keys($this->resolving)) . ' -> ' . $abstract;
            throw new CircularDependencyException("Circular dependency detected while resolving [{$abstract}]: {$chain}");
        }

        $this->resolving[$abstract] = true;

        try {
            // Singleton — resolve once, cache
            if (isset($this->singletonFactories[$abstract])) {
                $object = ($this->singletonFactories[$abstract])($this, $parameters);
                $this->instances[$abstract] = $object;
                return $object;
            }

            // Transient — new instance every call
            if (isset($this->bindings[$abstract])) {
                return ($this->bindings[$abstract])($this, $parameters);
            }

            // Autowire concrete class
            if (class_exists($abstract)) {
                return $this->build($abstract, $parameters);
            }
        } finally {
            unset($this->resolving[$abstract]);
        }

        throw new NotFoundException("No binding found for [{$abstract}] in the container.");
    }

    /**
     * PSR-11: find an entry of the container by its identifier.
     *
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    /**
     * Determine if an entry has been explicitly registered
     * (bind, singleton or instance). Autowirable classes that were never
     * registered return false — use make()/get() to build them.
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances)
            || isset($this->singletonFactories[$id])
            || isset($this->bindings[$id]);
    }

    /**
     * Alias of has() — explicit registration check.
     */
    public function bound(string $abstract): bool
    {
        return $this->has($abstract);
    }

    /**
     * Build a concrete class, autowiring its constructor dependencies.
     *
     * @param  string               $class
     * @param  array<string, mixed> $parameters Named overrides
     * @throws ContainerException
     */
    public function build(string $class, array $parameters = []): object
    {
        if (!class_exists($class)) {
            throw new NotFoundException("Class [{$class}] does not exist.");
        }

        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Target [{$class}] is not instantiable. Bind it to a concrete implementation.");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }
            $arguments[] = $this->resolveParameter($class, $parameter, $parameters);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    // ── Path helpers ──────────────────────────────────────────────────────────

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    public function configPath(string $path = ''): string
    {
        return $this->basePath('config') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    public function storagePath(string $path = ''): string
    {
        return $this->basePath('storage') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    public function viewsPath(string $path = ''): string
    {
        return $this->basePath('views') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    public function cachePath(string $path = ''): string
    {
        return $this->storagePath('cache') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    public function langPath(string $path = ''): string
    {
        return $this->basePath('lang') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    public function routesPath(string $path = ''): string
    {
        return $this->basePath('routes') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Turn a bind()/singleton() concrete into a uniform factory.
     *
     * @return \Closure(self, array<string, mixed>): mixed
     */
    private function normalizeConcrete(string $abstract, callable|string|null $concrete): \Closure
    {
        $concrete ??= $abstract;

        // A class name — autowire it (interface → implementation, or self-binding).
        if (is_string($concrete) && (class_exists($concrete) || interface_exists($concrete))) {
            if ($concrete === $abstract) {
                return fn (self $app, array $parameters) => $app->build($concrete, $parameters);
            }
            return fn (self $app, array $parameters) => $app->make($concrete, $parameters);
        }

        if (is_callable($concrete)) {
            $factory = \Closure::fromCallable($concrete);
            return fn (self $app, array $parameters) => $factory($app, $parameters);
        }

        throw new ContainerException("Invalid binding for [{$abstract}]: expected a callable or an existing class name.");
    }

    /**
     * Resolve a single constructor parameter.
     *
     * @param array<string, mixed> $parameters
     */
    private function resolveParameter(string $class, \ReflectionParameter $parameter, array $parameters): mixed
    {
        $name = $parameter->getName();

        if (array_key_exists($name, $parameters)) {
            return $parameters[$name];
        }

        foreach ($this->classTypesOf($parameter) as $type) {
            if ($type === Application::class || $type === ContainerInterface::class) {
                return $this;
            }

            if (!$this->has($type) && !class_exists($type)) {
                continue; // unbound interface — try defaults below
            }

            try {
                return $this->make($type);
            } catch (CircularDependencyException $e) {
                throw $e;
            } catch (ContainerException $e) {
                if ($parameter->isDefaultValueAvailable() || $parameter->allowsNull()) {
                    break;
                }
                throw new ContainerException(
                    "Unresolvable dependency [\${$name}] of type [{$type}] in class [{$class}]: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new ContainerException("Unresolvable dependency [\${$name}] in class [{$class}].");
    }

    /**
     * Class/interface names declared on a parameter (named or union types).
     *
     * @return array<int, string>
     */
    private function classTypesOf(\ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        $types = match (true) {
            $type instanceof \ReflectionNamedType => [$type],
            $type instanceof \ReflectionUnionType => $type->getTypes(),
            default => [],
        };

        $names = [];
        foreach ($types as $t) {
            if ($t instanceof \ReflectionNamedType && !$t->isBuiltin()) {
                $typeName = $t->getName();
                if ($typeName === 'self' || $typeName === 'static') {
                    $declaring = $parameter->getDeclaringClass();
                    if ($declaring === null) {
                        continue;
                    }
                    $typeName = $declaring->getName();
                }
                $names[] = $typeName;
            }
        }

        return $names;
    }
}

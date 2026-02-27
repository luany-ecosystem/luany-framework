<?php

namespace Luany\Framework;

use Luany\Framework\Contracts\ApplicationInterface;
use Luany\Framework\Contracts\ServiceProviderInterface;

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
 */
class Application implements ApplicationInterface
{
    /** Transient factory bindings */
    private array $bindings = [];

    /** Shared singleton factories */
    private array $singletonFactories = [];

    /** Resolved singleton instances */
    private array $instances = [];

    /** Registered service providers */
    private array $providers = [];

    /** Whether bootProviders() has been called */
    private bool $booted = false;

    /** Absolute path to the application root */
    private string $basePath;

    /** Global application instance */
    private static ?self $instance = null;

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
        return static::$instance;
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
    public function getProviders(): array
    {
        return $this->providers;
    }

    // ── Container ─────────────────────────────────────────────────────────────

    public function bind(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract] = $factory;
    }

    public function singleton(string $abstract, callable $factory): void
    {
        $this->singletonFactories[$abstract] = $factory;
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function make(string $abstract): mixed
    {
        // Pre-built instance
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Singleton — resolve once, cache
        if (isset($this->singletonFactories[$abstract])) {
            $this->instances[$abstract] = ($this->singletonFactories[$abstract])($this);
            return $this->instances[$abstract];
        }

        // Transient — new instance every call
        if (isset($this->bindings[$abstract])) {
            return ($this->bindings[$abstract])($this);
        }

        // Auto-resolve concrete class (no constructor params)
        if (class_exists($abstract)) {
            return $this->autoResolve($abstract);
        }

        throw new \RuntimeException("No binding found for [{$abstract}] in the container.");
    }

    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract])
            || isset($this->singletonFactories[$abstract])
            || isset($this->bindings[$abstract]);
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

    public function routesPath(string $path = ''): string
    {
        return $this->basePath('routes') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Auto-resolve a concrete class with no constructor dependencies.
     * For classes that need dependencies, use bind() or singleton() explicitly.
     */
    private function autoResolve(string $class): mixed
    {
        $reflection   = new \ReflectionClass($class);
        $constructor  = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return new $class();
        }

        throw new \RuntimeException(
            "Cannot auto-resolve [{$class}] — it has constructor dependencies. Use bind() or singleton()."
        );
    }
}
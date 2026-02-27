<?php

namespace Luany\Framework\Contracts;

use Luany\Framework\Application;

/**
 * ServiceProviderInterface
 *
 * Contract for all Luany service providers.
 *
 * Two-phase lifecycle:
 *   register() → bind services into the container (no cross-provider dependencies)
 *   boot()     → use services that are now fully registered (safe to call make())
 *
 * Usage:
 *   class DatabaseServiceProvider implements ServiceProviderInterface
 *   {
 *       public function register(Application $app): void
 *       {
 *           $app->singleton('db', fn() => new Database(env('DB_DSN')));
 *       }
 *
 *       public function boot(Application $app): void
 *       {
 *           // Run migrations check, set up listeners, etc.
 *       }
 *   }
 */
interface ServiceProviderInterface
{
    /**
     * Register bindings into the container.
     * Do NOT call make() here — other providers may not be registered yet.
     */
    public function register(Application $app): void;

    /**
     * Boot the provider after all providers have been registered.
     * Safe to call make() and resolve dependencies here.
     */
    public function boot(Application $app): void;
}
<?php

namespace Luany\Framework;

use Luany\Framework\Contracts\ServiceProviderInterface;

/**
 * ServiceProvider
 *
 * Abstract base class for service providers.
 * Extend this instead of implementing ServiceProviderInterface directly
 * when you only need to override one of the two methods.
 *
 * Usage:
 *   class MailServiceProvider extends ServiceProvider
 *   {
 *       public function register(Application $app): void
 *       {
 *           $app->singleton('mailer', fn() => new Mailer(
 *               host: env('MAIL_HOST'),
 *               port: env('MAIL_PORT', 587),
 *           ));
 *       }
 *   }
 */
abstract class ServiceProvider implements ServiceProviderInterface
{
    /**
     * Register bindings into the container.
     * Override to add your bindings.
     */
    public function register(Application $app): void
    {
        // Override in subclass
    }

    /**
     * Boot the provider after all providers have been registered.
     * Override to add post-registration behaviour.
     */
    public function boot(Application $app): void
    {
        // Override in subclass
    }
}
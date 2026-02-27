<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Application;
use Luany\Framework\Contracts\ServiceProviderInterface;
use Luany\Framework\ServiceProvider;
use PHPUnit\Framework\TestCase;

// ── Test providers ────────────────────────────────────────────────────────────

class BindingProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $app->singleton('test.service', fn() => new \stdClass());
    }
}

class BootTrackingProvider extends ServiceProvider
{
    public static array $log = [];

    public function register(Application $app): void
    {
        static::$log[] = 'registered';
    }

    public function boot(Application $app): void
    {
        static::$log[] = 'booted';
    }
}

class OrderTrackingProvider extends ServiceProvider
{
    public function __construct(private string $name, private array &$log) {}

    public function register(Application $app): void
    {
        $this->log[] = "register:{$this->name}";
    }

    public function boot(Application $app): void
    {
        $this->log[] = "boot:{$this->name}";
    }
}

class DependentProvider extends ServiceProvider
{
    public function boot(Application $app): void
    {
        // Safe to call make() in boot — all providers registered by now
        $app->instance('boot.result', $app->make('test.service'));
    }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

class ServiceProviderTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application('/tmp/luany-provider-test');
        BootTrackingProvider::$log = [];
    }

    // ── Interface ─────────────────────────────────────────────────────────────

    public function test_service_provider_implements_interface(): void
    {
        $provider = new BindingProvider();
        $this->assertInstanceOf(ServiceProviderInterface::class, $provider);
    }

    // ── register ─────────────────────────────────────────────────────────────

    public function test_register_calls_provider_register_immediately(): void
    {
        $this->app->register(new BindingProvider());
        $this->assertTrue($this->app->has('test.service'));
    }

    public function test_register_adds_to_providers_list(): void
    {
        $provider = new BindingProvider();
        $this->app->register($provider);
        $this->assertContains($provider, $this->app->getProviders());
    }

    public function test_register_logs_register_call(): void
    {
        $this->app->register(new BootTrackingProvider());
        $this->assertContains('registered', BootTrackingProvider::$log);
    }

    public function test_register_does_not_call_boot_immediately(): void
    {
        $this->app->register(new BootTrackingProvider());
        $this->assertNotContains('booted', BootTrackingProvider::$log);
    }

    // ── bootProviders ─────────────────────────────────────────────────────────

    public function test_boot_providers_calls_boot_on_all(): void
    {
        $this->app->register(new BootTrackingProvider());
        $this->app->bootProviders();
        $this->assertContains('booted', BootTrackingProvider::$log);
    }

    public function test_boot_providers_is_idempotent(): void
    {
        $this->app->register(new BootTrackingProvider());
        $this->app->bootProviders();
        $this->app->bootProviders();
        $this->app->bootProviders();

        $bootCount = count(array_filter(BootTrackingProvider::$log, fn($e) => $e === 'booted'));
        $this->assertSame(1, $bootCount);
    }

    // ── lifecycle order ───────────────────────────────────────────────────────

    public function test_all_register_before_any_boot(): void
    {
        $log = [];
        $this->app->register(new OrderTrackingProvider('A', $log));
        $this->app->register(new OrderTrackingProvider('B', $log));
        $this->app->register(new OrderTrackingProvider('C', $log));
        $this->app->bootProviders();

        // All register calls happen during register(), boot calls during bootProviders()
        $registers = array_slice($log, 0, 3);
        $boots     = array_slice($log, 3, 3);

        $this->assertSame(['register:A', 'register:B', 'register:C'], $registers);
        $this->assertSame(['boot:A', 'boot:B', 'boot:C'], $boots);
    }

    // ── cross-provider dependency in boot ─────────────────────────────────────

    public function test_boot_can_resolve_bindings_from_other_providers(): void
    {
        $this->app->register(new BindingProvider());
        $this->app->register(new DependentProvider());
        $this->app->bootProviders();

        $this->assertTrue($this->app->has('boot.result'));
        $this->assertInstanceOf(\stdClass::class, $this->app->make('boot.result'));
    }

    // ── getProviders ──────────────────────────────────────────────────────────

    public function test_get_providers_returns_all_registered(): void
    {
        $a = new BindingProvider();
        $b = new BootTrackingProvider();
        $this->app->register($a);
        $this->app->register($b);

        $providers = $this->app->getProviders();
        $this->assertCount(2, $providers);
        $this->assertSame($a, $providers[0]);
        $this->assertSame($b, $providers[1]);
    }

    // ── base ServiceProvider defaults ─────────────────────────────────────────

    public function test_base_provider_register_is_noop_by_default(): void
    {
        $provider = new class extends ServiceProvider {};
        $provider->register($this->app); // should not throw
        $this->assertTrue(true);
    }

    public function test_base_provider_boot_is_noop_by_default(): void
    {
        $provider = new class extends ServiceProvider {};
        $provider->boot($this->app); // should not throw
        $this->assertTrue(true);
    }
}
<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Application;
use Luany\Framework\Contracts\ApplicationInterface;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application('/tmp/luany-test');
    }

    // ── Self registration ─────────────────────────────────────────────────────

    public function test_app_registers_itself(): void
    {
        $this->assertSame($this->app, $this->app->make(Application::class));
    }

    public function test_app_registers_interface(): void
    {
        $this->assertSame($this->app, $this->app->make(ApplicationInterface::class));
    }

    public function test_get_instance_returns_global(): void
    {
        $this->assertSame($this->app, Application::getInstance());
    }

    // ── bind ──────────────────────────────────────────────────────────────────

    public function test_bind_creates_new_instance_every_call(): void
    {
        $this->app->bind('counter', fn() => new \stdClass());
        $a = $this->app->make('counter');
        $b = $this->app->make('counter');
        $this->assertNotSame($a, $b);
    }

    public function test_bind_factory_receives_app(): void
    {
        $received = null;
        $this->app->bind('test', function ($app) use (&$received) {
            $received = $app;
            return new \stdClass();
        });
        $this->app->make('test');
        $this->assertSame($this->app, $received);
    }

    // ── singleton ─────────────────────────────────────────────────────────────

    public function test_singleton_returns_same_instance(): void
    {
        $this->app->singleton('service', fn() => new \stdClass());
        $a = $this->app->make('service');
        $b = $this->app->make('service');
        $this->assertSame($a, $b);
    }

    public function test_singleton_factory_called_once(): void
    {
        $count = 0;
        $this->app->singleton('counted', function () use (&$count) {
            $count++;
            return new \stdClass();
        });
        $this->app->make('counted');
        $this->app->make('counted');
        $this->app->make('counted');
        $this->assertSame(1, $count);
    }

    // ── instance ──────────────────────────────────────────────────────────────

    public function test_instance_returns_registered_object(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        $this->app->instance('my.obj', $obj);
        $this->assertSame($obj, $this->app->make('my.obj'));
    }

    // ── has ───────────────────────────────────────────────────────────────────

    public function test_has_returns_true_for_bound(): void
    {
        $this->app->bind('thing', fn() => new \stdClass());
        $this->assertTrue($this->app->has('thing'));
    }

    public function test_has_returns_true_for_singleton(): void
    {
        $this->app->singleton('thing2', fn() => new \stdClass());
        $this->assertTrue($this->app->has('thing2'));
    }

    public function test_has_returns_true_for_instance(): void
    {
        $this->app->instance('thing3', new \stdClass());
        $this->assertTrue($this->app->has('thing3'));
    }

    public function test_has_returns_false_for_unbound(): void
    {
        $this->assertFalse($this->app->has('nonexistent'));
    }

    // ── make errors ──────────────────────────────────────────────────────────

    public function test_make_throws_for_unknown_abstract(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->app->make('nonexistent.binding');
    }

    // ── auto-resolve ─────────────────────────────────────────────────────────

    public function test_auto_resolve_no_constructor(): void
    {
        $obj = $this->app->make(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $obj);
    }

    // ── paths ─────────────────────────────────────────────────────────────────

    public function test_base_path(): void
    {
        $this->assertSame('/tmp/luany-test', $this->app->basePath());
    }

    public function test_base_path_with_segment(): void
    {
        $this->assertSame('/tmp/luany-test' . DIRECTORY_SEPARATOR . 'config', $this->app->basePath('config'));
    }

    public function test_storage_path(): void
    {
        $this->assertSame('/tmp/luany-test' . DIRECTORY_SEPARATOR . 'storage', $this->app->storagePath());
    }

    public function test_views_path(): void
    {
        $this->assertSame('/tmp/luany-test' . DIRECTORY_SEPARATOR . 'views', $this->app->viewsPath());
    }

    public function test_cache_path(): void
    {
        $expected = '/tmp/luany-test' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        $this->assertSame($expected, $this->app->cachePath());
    }

    public function test_routes_path(): void
    {
        $this->assertSame('/tmp/luany-test' . DIRECTORY_SEPARATOR . 'routes', $this->app->routesPath());
    }

    public function test_config_path(): void
    {
        $this->assertSame('/tmp/luany-test' . DIRECTORY_SEPARATOR . 'config', $this->app->configPath());
    }
}
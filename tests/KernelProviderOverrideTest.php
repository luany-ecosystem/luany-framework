<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Application;
use Luany\Framework\Contracts\SessionInterface;
use Luany\Framework\Security\CsrfToken;
use Luany\Framework\ServiceProvider;
use Luany\Framework\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for issue #2 — Kernel::boot() must not overwrite provider bindings.
 */
class KernelProviderOverrideTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(sys_get_temp_dir() . '/luany-override-' . uniqid());
    }

    public function test_provider_bindings_survive_boot(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $config  = new Config('/nonexistent');
        $view    = new \stdClass();
        $csrf    = new CsrfToken($session);

        $this->app->register(new class($session, $config, $view, $csrf) extends ServiceProvider {
            public function __construct(
                private SessionInterface $session,
                private Config $config,
                private object $view,
                private CsrfToken $csrf,
            ) {
            }

            public function register(Application $app): void
            {
                $app->instance('session', $this->session);
                $app->instance('config', $this->config);
                $app->instance('view', $this->view);
                $app->instance('csrf', $this->csrf);
            }
        });

        (new TestKernel($this->app))->boot();

        $this->assertSame($session, $this->app->make('session'));
        $this->assertSame($session, $this->app->make(SessionInterface::class));
        $this->assertSame($config, $this->app->make('config'));
        $this->assertSame($config, $this->app->make(Config::class));
        $this->assertSame($view, $this->app->make('view'));
        $this->assertSame($csrf, $this->app->make('csrf'));
        $this->assertSame($csrf, $this->app->make(CsrfToken::class));
    }

    public function test_provider_binding_by_class_is_used_for_string_key(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $this->app->instance(SessionInterface::class, $session);

        (new TestKernel($this->app))->boot();

        $this->assertSame($session, $this->app->make('session'));
    }

    public function test_defaults_are_registered_when_no_provider(): void
    {
        (new TestKernel($this->app))->boot();

        foreach (['config', 'session', 'csrf', 'view', Config::class, SessionInterface::class, CsrfToken::class] as $key) {
            $this->assertTrue($this->app->bound($key), "{$key} should be bound");
        }
        $this->assertInstanceOf(Config::class, $this->app->make('config'));
    }
}

<?php

namespace Luany\Framework\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Routing\Route;
use Luany\Core\Routing\Router;
use Luany\Framework\Application;
use Luany\Framework\Container\ContainerException;
use Luany\Framework\Container\MissingModelException;
use PHPUnit\Framework\TestCase;

interface MiGreeter
{
    public function greet(string $name): string;
}

final class MiHelloGreeter implements MiGreeter
{
    public function greet(string $name): string
    {
        return "hello {$name}";
    }
}

final class MiClock
{
    public string $now = '12:00';
}

final class MiUser
{
    public function __construct(public string $name) {}
}

final class MiController
{
    public function __construct(private MiClock $clock) {}

    // Service by type + route parameter by name, in any order
    public function show(MiGreeter $greeter, string $name, Request $request): string
    {
        return $greeter->greet($name) . ' @' . $this->clock->now . ' ' . $request->method();
    }

    // Legacy positional signature: names do not match the route ({id})
    public function legacy($request, $slug): string
    {
        return get_class($request) . ':' . $slug;
    }

    public function profile(Request $request, MiUser $user): string
    {
        return 'profile:' . $user->name;
    }

    public function optional(Request $request, ?MiUser $user = null): string
    {
        return $user === null ? 'guest' : $user->name;
    }

    public function typedId(int $id): string
    {
        return gettype($id) . ':' . $id;
    }
}

class MethodInjectionTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        if (!method_exists(Router::class, 'setInvoker')) {
            $this->markTestSkipped('Requires luany/core >= 1.2 (Router::setInvoker).');
        }

        Route::reset();
        $this->app = new Application(sys_get_temp_dir() . '/luany-mi-' . uniqid());
        $this->app->bind(MiGreeter::class, MiHelloGreeter::class);
    }

    protected function tearDown(): void
    {
        Route::reset();
    }

    private function kernel(): TestKernel
    {
        $kernel = new TestKernel($this->app);
        $kernel->boot();
        return $kernel;
    }

    // ── Kernel integration ───────────────────────────────────────────────────

    public function test_services_by_type_and_route_params_by_name(): void
    {
        $kernel = $this->kernel();
        Route::get('/hi/{name}', [MiController::class, 'show']);

        $this->assertSame('hello ana @12:00 GET', $kernel->handle(new Request('GET', '/hi/ana'))->getBody());
    }

    public function test_legacy_positional_signatures_keep_working(): void
    {
        $kernel = $this->kernel();
        Route::get('/posts/{id}', [MiController::class, 'legacy']);

        $this->assertSame(Request::class . ':42', $kernel->handle(new Request('GET', '/posts/42'))->getBody());
    }

    public function test_closure_routes_get_injection_too(): void
    {
        $kernel = $this->kernel();
        Route::get('/c/{name}', fn (MiGreeter $g, string $name) => Response::make($g->greet($name)));

        $this->assertSame('hello rui', $kernel->handle(new Request('GET', '/c/rui'))->getBody());
    }

    public function test_bound_model_is_injected_by_name(): void
    {
        $kernel = $this->kernel();
        Route::bind('user', fn (string $v) => $v === 'missing' ? null : new MiUser(ucfirst($v)));
        Route::get('/u/{user}', [MiController::class, 'profile']);

        $this->assertSame('profile:Ana', $kernel->handle(new Request('GET', '/u/ana'))->getBody());
    }

    public function test_missing_bound_model_returns_404_not_type_error(): void
    {
        $kernel = $this->kernel();
        Route::bind('user', fn () => null);
        Route::get('/u/{user}', [MiController::class, 'profile']);

        $this->assertSame(404, $kernel->handle(new Request('GET', '/u/nobody'))->getStatusCode());
    }

    public function test_nullable_model_parameter_accepts_null(): void
    {
        $kernel = $this->kernel();
        Route::bind('user', fn () => null);
        Route::get('/o/{user}', [MiController::class, 'optional']);

        $this->assertSame('guest', $kernel->handle(new Request('GET', '/o/x'))->getBody());
    }

    public function test_scalar_route_params_are_coerced_to_declared_type(): void
    {
        $kernel = $this->kernel();
        Route::get('/n/{id}', [MiController::class, 'typedId']);

        $this->assertSame('integer:7', $kernel->handle(new Request('GET', '/n/7'))->getBody());
    }

    // ── Application::call() directly ─────────────────────────────────────────

    public function test_call_uses_defaults_and_variadics(): void
    {
        $this->assertSame('a-b-c', $this->app->call(fn (string $x, string ...$rest) => implode('-', [$x, ...$rest]), ['a', 'b', 'c']));
        $this->assertSame('dflt', $this->app->call(fn (string $v = 'dflt') => $v));
    }

    public function test_call_resolves_class_string_callables_through_container(): void
    {
        $this->assertSame('hello zé', $this->app->call([MiHelloGreeter::class, 'greet'], ['name' => 'zé']));
    }

    public function test_call_reports_unresolvable_parameters(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Unresolvable parameter [$needed]');
        $this->app->call(fn (string $needed) => $needed);
    }

    public function test_call_throws_missing_model_for_null_required_object(): void
    {
        $this->expectException(MissingModelException::class);
        $this->app->call(fn (MiUser $user) => $user, ['user' => null]);
    }
}

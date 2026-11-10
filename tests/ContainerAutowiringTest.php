<?php

namespace Luany\Framework\Tests;

use Luany\Framework\Application;
use Luany\Framework\Container\CircularDependencyException;
use Luany\Framework\Container\ContainerException;
use Luany\Framework\Container\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

interface AwGreeter
{
    public function greet(): string;
}

class AwEnglishGreeter implements AwGreeter
{
    public function greet(): string
    {
        return 'hello';
    }
}

class AwDependency
{
}

class AwService
{
    public function __construct(
        public AwDependency $dependency,
        public AwGreeter $greeter,
        public string $name = 'default',
        public ?AwUnboundInterface $optional = null,
    ) {
    }
}

interface AwUnboundInterface
{
}

class AwNeedsScalar
{
    public function __construct(public int $size)
    {
    }
}

class AwNeedsApp
{
    public function __construct(public Application $app, public ContainerInterface $container)
    {
    }
}

class AwCircularA
{
    public function __construct(public AwCircularB $b)
    {
    }
}

class AwCircularB
{
    public function __construct(public AwCircularA $a)
    {
    }
}

abstract class AwAbstract
{
}

/**
 * Regression tests for issue #7 — autowiring, interface binding, PSR-11.
 */
class ContainerAutowiringTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application('/tmp/luany-container-test');
    }

    public function test_application_is_psr11_container(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, $this->app);
    }

    public function test_autowires_constructor_dependencies(): void
    {
        $this->app->bind(AwGreeter::class, AwEnglishGreeter::class);

        $service = $this->app->make(AwService::class);

        $this->assertInstanceOf(AwService::class, $service);
        $this->assertInstanceOf(AwDependency::class, $service->dependency);
        $this->assertSame('hello', $service->greeter->greet());
        $this->assertSame('default', $service->name);
        $this->assertNull($service->optional);
    }

    public function test_named_parameters_override_autowiring(): void
    {
        $this->app->bind(AwGreeter::class, AwEnglishGreeter::class);

        $service = $this->app->make(AwService::class, ['name' => 'custom']);

        $this->assertSame('custom', $service->name);
    }

    public function test_interface_binding_to_concrete_class(): void
    {
        $this->app->bind(AwGreeter::class, AwEnglishGreeter::class);

        $this->assertInstanceOf(AwEnglishGreeter::class, $this->app->make(AwGreeter::class));
        $this->assertNotSame($this->app->make(AwGreeter::class), $this->app->make(AwGreeter::class));
    }

    public function test_singleton_with_class_name_is_shared(): void
    {
        $this->app->singleton(AwGreeter::class, AwEnglishGreeter::class);

        $this->assertSame($this->app->make(AwGreeter::class), $this->app->make(AwGreeter::class));
    }

    public function test_singleton_without_concrete_autowires_itself(): void
    {
        $this->app->singleton(AwDependency::class);

        $this->assertSame($this->app->make(AwDependency::class), $this->app->make(AwDependency::class));
    }

    public function test_application_and_container_are_injected(): void
    {
        $obj = $this->app->make(AwNeedsApp::class);

        $this->assertSame($this->app, $obj->app);
        $this->assertSame($this->app, $obj->container);
    }

    public function test_unresolvable_scalar_throws_container_exception(): void
    {
        $this->expectException(ContainerExceptionInterface::class);
        $this->expectExceptionMessage('$size');

        $this->app->make(AwNeedsScalar::class);
    }

    public function test_unbound_interface_without_default_throws(): void
    {
        $this->expectException(ContainerException::class);

        $this->app->make(AwGreeter::class);
    }

    public function test_abstract_class_is_not_instantiable(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('not instantiable');

        $this->app->make(AwAbstract::class);
    }

    public function test_circular_dependency_is_detected(): void
    {
        try {
            $this->app->make(AwCircularA::class);
            $this->fail('Expected CircularDependencyException');
        } catch (CircularDependencyException $e) {
            $this->assertStringContainsString(AwCircularA::class, $e->getMessage());
            $this->assertStringContainsString(AwCircularB::class, $e->getMessage());
        }
    }

    public function test_circular_dependency_through_factories_is_detected(): void
    {
        $this->app->singleton('a', fn (Application $app) => $app->make('b'));
        $this->app->singleton('b', fn (Application $app) => $app->make('a'));

        $this->expectException(CircularDependencyException::class);

        $this->app->make('a');
    }

    public function test_container_recovers_after_failed_resolution(): void
    {
        try {
            $this->app->make(AwCircularA::class);
        } catch (CircularDependencyException) {
        }

        // Resolution stack must be cleared — unrelated entries still resolve.
        $this->assertInstanceOf(AwDependency::class, $this->app->make(AwDependency::class));
    }

    public function test_get_throws_psr_not_found(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);

        $this->app->get('missing.service');
    }

    public function test_not_found_is_still_runtime_exception(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->app->make('missing.service');
    }

    public function test_get_and_has(): void
    {
        $this->app->instance('answer', 42);

        $this->assertTrue($this->app->has('answer'));
        $this->assertTrue($this->app->bound('answer'));
        $this->assertSame(42, $this->app->get('answer'));
        $this->assertFalse($this->app->has('question'));
    }

    public function test_null_instance_is_bound(): void
    {
        $this->app->instance('nothing', null);

        $this->assertTrue($this->app->has('nothing'));
        $this->assertNull($this->app->make('nothing'));
    }

    public function test_invalid_binding_throws(): void
    {
        $this->expectException(ContainerException::class);

        $this->app->bind('thing', 'Not\\A\\Real\\ClassOrFunction');
    }
}

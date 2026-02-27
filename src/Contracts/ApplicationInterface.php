<?php

namespace Luany\Framework\Contracts;

/**
 * ApplicationInterface
 *
 * Contract for the Luany DI container.
 * Defines how services are registered and resolved.
 *
 * Deliberately minimal — builds only what the framework needs.
 * Not PSR-11 to avoid external interface dependency,
 * but compatible in spirit: bind, singleton, make.
 */
interface ApplicationInterface
{
    /**
     * Register a transient binding.
     * A new instance is created on every make() call.
     */
    public function bind(string $abstract, callable $factory): void;

    /**
     * Register a shared binding.
     * The same instance is returned on every make() call.
     */
    public function singleton(string $abstract, callable $factory): void;

    /**
     * Register a pre-built instance.
     * Identical to singleton but accepts the object directly.
     */
    public function instance(string $abstract, mixed $instance): void;

    /**
     * Resolve a binding from the container.
     *
     * @throws \RuntimeException if the abstract is not bound
     */
    public function make(string $abstract): mixed;

    /**
     * Determine if a binding exists.
     */
    public function has(string $abstract): bool;
}
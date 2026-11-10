<?php

namespace Luany\Framework\Container;

/**
 * Thrown by Application::call() when a named argument (e.g. a route-model
 * binding) resolved to null for a parameter that requires an object.
 * The HTTP Kernel turns it into a 404.
 */
final class MissingModelException extends ContainerException
{
    public function __construct(public readonly string $parameter, public readonly string $type)
    {
        parent::__construct("No [{$type}] found for parameter [\${$parameter}].");
    }
}

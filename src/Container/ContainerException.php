<?php

namespace Luany\Framework\Container;

use Psr\Container\ContainerExceptionInterface;

/**
 * ContainerException
 *
 * Thrown when an entry exists but cannot be built (unresolvable constructor
 * parameter, non-instantiable class, factory failure, ...).
 *
 * Extends \RuntimeException for backward compatibility with code that
 * caught RuntimeException from Application::make().
 */
class ContainerException extends \RuntimeException implements ContainerExceptionInterface
{
}

<?php

namespace Luany\Framework\Container;

use Psr\Container\NotFoundExceptionInterface;

/**
 * NotFoundException
 *
 * Thrown when no binding exists for an identifier and the identifier is not
 * an existing class that could be autowired.
 */
class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}

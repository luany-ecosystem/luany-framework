<?php

namespace Luany\Framework\Container;

/**
 * CircularDependencyException
 *
 * Thrown when resolving an entry requires resolving itself again
 * (A → B → A). The message contains the full resolution chain.
 */
class CircularDependencyException extends ContainerException
{
}

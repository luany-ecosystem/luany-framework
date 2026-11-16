<?php

namespace Luany\Database;

/**
 * Stand-in for luany/database's exception (not a dependency of the
 * framework). Loaded only when the real class is not installed.
 */
class ModelNotFoundException extends \RuntimeException
{
}

<?php

namespace Luany\Framework\Support;

use Dotenv\Dotenv;

/**
 * Env
 *
 * Encapsulated wrapper around vlucas/phpdotenv.
 * The phpdotenv dependency never leaks outside this class.
 *
 * Usage (in Application bootstrap):
 *   Env::load($app->basePath());
 *
 * Usage (anywhere):
 *   Env::get('DB_HOST', 'localhost');
 *   Env::required(['DB_HOST', 'DB_NAME']); // throws if missing
 */
class Env
{
    private static bool $loaded = false;

    /**
     * Load the .env file from the given base path.
     * Safe — does nothing if .env does not exist.
     * Idempotent — calling more than once has no effect.
     */
    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }

        if (file_exists($basePath . DIRECTORY_SEPARATOR . '.env')) {
            $dotenv = Dotenv::createImmutable($basePath);
            $dotenv->safeLoad();
        }

        self::$loaded = true;
    }

    /**
     * Get an environment variable value.
     * Checks $_ENV first, then $_SERVER, then getenv().
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true',  '(true)'  => true,
            'false', '(false)' => false,
            'null',  '(null)'  => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }

    /**
     * Assert that required environment variables are set.
     *
     * @throws \RuntimeException if any key is missing
     */
    public static function required(array $keys): void
    {
        $missing = [];

        foreach ($keys as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if ($value === false || $value === null || $value === '') {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Required environment variables are not set: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Reset loaded state — for testing only.
     * @internal
     */
    public static function reset(): void
    {
        self::$loaded = false;
    }
}
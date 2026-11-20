<?php

namespace Luany\Framework\Support;

use Dotenv\Dotenv;
use Dotenv\Loader\Loader;
use Dotenv\Parser\Parser;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Store\StringStore;

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
     * Load the environment from the given base path.
     *
     *   1. .env, when it exists (local development) — as always.
     *   2. Otherwise the encrypted file of APP_ENV (.env.production.encrypted),
     *      then .env.encrypted, decrypted in memory with LUANY_ENV_KEY_{ENV} /
     *      LUANY_ENV_KEY from the real environment. See EnvironmentCipher.
     *
     * Real environment variables always win (immutable). Idempotent.
     *
     * @throws \RuntimeException an encrypted file exists but its key is missing or wrong
     */
    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }

        $dir = rtrim($basePath, '/\\');
        if (file_exists($dir . DIRECTORY_SEPARATOR . '.env')) {
            Dotenv::createImmutable($dir)->safeLoad();
        } elseif (($encrypted = self::encryptedFile($dir)) !== null) {
            self::loadEncrypted($dir, ...$encrypted);
        }

        self::$loaded = true;
    }

    /**
     * The encrypted environment file to use when there is no .env:
     * [file name, environment] or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function encryptedFile(string $basePath): ?array
    {
        $appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
        $appEnv = is_string($appEnv) ? strtolower(trim($appEnv)) : '';

        $candidates = [];
        if ($appEnv !== '' && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $appEnv) === 1) {
            $candidates[] = $appEnv;
        }
        $candidates[] = '';

        foreach ($candidates as $environment) {
            $file = EnvironmentCipher::fileName($environment);
            if (is_file($basePath . DIRECTORY_SEPARATOR . $file)) {
                return [$file, $environment];
            }
        }

        return null;
    }

    private static function loadEncrypted(string $basePath, string $file, string $environment): void
    {
        $key = EnvironmentCipher::keyFromEnvironment($environment);
        if ($key === null) {
            throw new \RuntimeException(sprintf(
                '%s was found but %s is not set. Define it in the server environment (or CI secrets) to decrypt it.',
                $file,
                EnvironmentCipher::keyVariable($environment)
            ));
        }

        $plain = EnvironmentCipher::decrypt((string) file_get_contents($basePath . DIRECTORY_SEPARATOR . $file), $key, $environment);

        $repository = RepositoryBuilder::createWithDefaultAdapters()->immutable()->make();
        (new Dotenv(new StringStore($plain), new Parser(), new Loader(), $repository))->load();
    }

    /**
     * Get an environment variable value.
     * Checks $_ENV first, then $_SERVER, then getenv().
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false) {
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
    /** @param array<int, string> $keys */
    public static function required(array $keys): void
    {
        $missing = [];

        foreach ($keys as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if ($value === false || $value === '') {
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
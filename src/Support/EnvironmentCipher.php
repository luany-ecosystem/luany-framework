<?php

namespace Luany\Framework\Support;

/**
 * EnvironmentCipher — encrypted environment files (.env.{environment}.encrypted).
 *
 * The file can be committed: it is useless without the key, which lives
 * outside the repository (LUANY_ENV_KEY_{ENVIRONMENT} or LUANY_ENV_KEY on the
 * server or in CI secrets). Env::load() decrypts it in memory when there is
 * no plain .env — no plaintext is written to disk.
 *
 * Cryptography is libsodium's XChaCha20-Poly1305 (authenticated encryption,
 * 24-byte random nonce). The environment name is bound as associated data,
 * so a staging file cannot be swapped in for the production one.
 *
 * File format:
 *   LUANY-ENV v1
 *   base64(nonce . ciphertext) wrapped at 76 characters
 *
 * Keys are 32 random bytes, written as "base64:…".
 */
final class EnvironmentCipher
{
    public const HEADER = 'LUANY-ENV v1';
    public const SUFFIX = '.encrypted';

    /** A new random key, formatted for LUANY_ENV_KEY. */
    public static function generateKey(): string
    {
        self::assertSodium();

        return 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
    }

    public static function encrypt(string $plaintext, string $key, string $environment = ''): string
    {
        self::assertSodium();
        $raw   = self::rawKey($key);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $box   = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::associatedData($environment), $nonce, $raw);
        sodium_memzero($raw);

        return self::HEADER . "\n" . rtrim(chunk_split(base64_encode($nonce . $box), 76, "\n")) . "\n";
    }

    /**
     * @throws \RuntimeException wrong key, wrong environment, tampered or malformed file
     */
    public static function decrypt(string $payload, string $key, string $environment = ''): string
    {
        self::assertSodium();
        $payload = trim(str_replace("\r", '', $payload));
        if (!str_starts_with($payload, self::HEADER . "\n")) {
            throw new \RuntimeException('Not an encrypted Luany environment file (missing "' . self::HEADER . '" header).');
        }

        $binary = base64_decode(str_replace("\n", '', substr($payload, strlen(self::HEADER) + 1)), true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($binary === false || strlen($binary) <= $nonceLength + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new \RuntimeException('The encrypted environment file is malformed.');
        }

        $raw   = self::rawKey($key);
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($binary, $nonceLength), self::associatedData($environment), substr($binary, 0, $nonceLength), $raw
        );
        sodium_memzero($raw);

        if ($plain === false) {
            throw new \RuntimeException(
                'Could not decrypt the environment file: wrong key, file of another environment, or modified file.'
            );
        }

        return $plain;
    }

    /** File name for an environment: '' → .env.encrypted, 'production' → .env.production.encrypted */
    public static function fileName(string $environment = ''): string
    {
        $environment = self::normalize($environment);

        return '.env' . ($environment === '' ? '' : '.' . $environment) . self::SUFFIX;
    }

    /** Environment variable holding the key: LUANY_ENV_KEY_PRODUCTION, or LUANY_ENV_KEY. */
    public static function keyVariable(string $environment = ''): string
    {
        $environment = self::normalize($environment);

        return 'LUANY_ENV_KEY' . ($environment === '' ? '' : '_' . strtoupper(str_replace(['-', '.'], '_', $environment)));
    }

    /**
     * The key from the real environment: the environment-specific variable
     * first, then LUANY_ENV_KEY. Null when neither is set.
     */
    public static function keyFromEnvironment(string $environment = ''): ?string
    {
        foreach (array_unique([self::keyVariable($environment), 'LUANY_ENV_KEY']) as $name) {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function normalize(string $environment): string
    {
        $environment = strtolower(trim($environment));
        if ($environment !== '' && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $environment) !== 1) {
            throw new \InvalidArgumentException("Invalid environment name [{$environment}]: use letters, digits, - or _.");
        }

        return $environment;
    }

    private static function associatedData(string $environment): string
    {
        return 'luany-env:v1:' . self::normalize($environment);
    }

    private static function rawKey(string $key): string
    {
        $key = trim($key);
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \RuntimeException('Invalid environment key: expected "base64:" followed by 32 random bytes (luany env:encrypt generates one).');
        }

        return $raw;
    }

    private static function assertSodium(): void
    {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new \RuntimeException('Encrypted environments need the sodium PHP extension (bundled with PHP; enable ext-sodium).');
        }
    }
}

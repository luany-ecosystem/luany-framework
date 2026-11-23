<?php

namespace Luany\Framework\Auth;

/**
 * Password hashing: Argon2id when PHP has it, bcrypt otherwise.
 *
 *   $hash = app('hash')->make($password);
 *   app('hash')->check($password, $hash);
 */
class Hasher
{
    /** @param array<string, int> $options password_hash() options (cost, memory_cost …) */
    public function __construct(
        private ?string $algorithm = null,
        private array $options = [],
    ) {
        $this->algorithm ??= defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public function make(string $password): string
    {
        return password_hash($password, $this->algorithm, $this->options);
    }

    public function check(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    /** True when the hash was made with another algorithm or cost (rehash after login). */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }
}

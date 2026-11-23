<?php

namespace Luany\Framework\Auth;

/**
 * Where the guard finds users. The default is ModelUserProvider (a
 * luany/database model such as App\Models\User).
 */
interface UserProvider
{
    public function retrieveById(int|string $id): ?object;

    /**
     * The user matching every credential except the password.
     *
     * @param array<string, mixed> $credentials
     */
    public function retrieveByCredentials(array $credentials): ?object;

    /** The stored password hash of a user. */
    public function passwordOf(object $user): string;

    public function idOf(object $user): int|string;

    /** Store a new hash (rehash after login, password reset). */
    public function updatePassword(object $user, string $hash): void;
}

<?php

namespace Luany\Framework\Auth;

/**
 * Storage of password reset tokens: one row per user, holding only the
 * SHA-256 of the token.
 */
interface TokenRepository
{
    /** Replace the user's token (any older one stops working). */
    public function save(string $userId, string $tokenHash, string $passwordFingerprint, int $createdAt): void;

    /** @return array{token_hash: string, password_fingerprint: string, created_at: int}|null */
    public function find(string $userId): ?array;

    public function delete(string $userId): void;
}

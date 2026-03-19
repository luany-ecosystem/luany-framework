<?php

namespace Luany\Framework\Contracts;

/**
 * SessionInterface
 *
 * Contract for session drivers.
 * Provides a pluggable interface — the framework ships with FileSession,
 * but applications can implement this with Redis, database, etc.
 *
 * Lifecycle:
 *   start() → get/set/flash → save()
 *
 * Flash data survives exactly one subsequent request.
 */
interface SessionInterface
{
    /**
     * Start or resume the session.
     */
    public function start(): void;

    /**
     * Get a value from the session.
     *
     * @param string $key     Session key
     * @param mixed  $default Fallback if key does not exist
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a value in the session.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Determine if a key exists in the session.
     */
    public function has(string $key): bool;

    /**
     * Remove a key from the session.
     */
    public function forget(string $key): void;

    /**
     * Flash a value for the next request only.
     */
    public function flash(string $key, mixed $value): void;

    /**
     * Regenerate the session ID.
     * Used after authentication to prevent session fixation.
     */
    public function regenerate(): void;

    /**
     * Save session data and close.
     */
    public function save(): void;

    /**
     * Get the current session ID.
     */
    public function getId(): string;

    /**
     * Get all session data.
     *
     * @return array<string, mixed>
     */
    public function all(): array;

    /**
     * Destroy the session completely.
     */
    public function destroy(): void;
}

<?php

namespace Luany\Framework\Auth;

use Luany\Core\RateLimit\RateLimiterInterface;

/**
 * Password reset.
 *
 *   // POST /forgot-password
 *   $status = app('auth.passwords')->sendResetLink(['email' => $email], $request->ip(),
 *       fn (object $user, string $token) => mailer()->to($email)->subject('…')
 *           ->send('emails.reset', ['url' => url('/reset-password?token=' . $token . '&email=' . urlencode($email))]));
 *   // Always show the same message: the status never says whether the email exists.
 *
 *   // POST /reset-password
 *   $status = app('auth.passwords')->reset(['email' => $email, 'token' => $token, 'password' => $new]);
 *
 * Tokens: 64 random hex characters, stored only as SHA-256, compared with
 * hash_equals(), single use, valid 60 minutes, one per user (a new one
 * replaces the old), and void as soon as the password changes by any other
 * route (the row keeps a fingerprint of the password hash). Requests are
 * limited per email and per IP.
 */
final class PasswordBroker
{
    public const RESET_LINK_SENT = 'reset-link-sent';
    public const PASSWORD_RESET  = 'password-reset';
    public const INVALID_TOKEN   = 'invalid-token';
    public const THROTTLED       = 'throttled';

    public function __construct(
        private UserProvider $users,
        private Hasher $hasher,
        private TokenRepository $tokens,
        private ?RateLimiterInterface $limiter = null,
        private int $ttl = 3600,
        private int $perEmail = 3,
        private int $perIp = 10,
        private int $window = 900,
    ) {
    }

    /**
     * @param array<string, mixed>                    $credentials e.g. ['email' => …]
     * @param callable(object, string): void          $send        receives the user and the plain token
     * @return self::RESET_LINK_SENT|self::THROTTLED
     */
    public function sendResetLink(array $credentials, string $ip, callable $send): string
    {
        unset($credentials['password'], $credentials['token']);

        if ($this->limiter !== null) {
            $id = strtolower(trim((string) json_encode($credentials)));
            $byEmail = $this->limiter->attempt('password-reset:id:' . hash('sha256', $id), $this->perEmail, $this->window);
            $byIp    = $this->limiter->attempt('password-reset:ip:' . $ip, $this->perIp, $this->window);
            if (!$byEmail || !$byIp) {
                return self::THROTTLED;
            }
        }

        $user = $this->users->retrieveByCredentials($credentials);
        if ($user !== null) {
            $send($user, $this->createToken($user));
        }

        // Same answer whether or not the user exists.
        return self::RESET_LINK_SENT;
    }

    public function createToken(object $user): string
    {
        $token = bin2hex(random_bytes(32));
        $this->tokens->save(
            (string) $this->users->idOf($user),
            hash('sha256', $token),
            self::fingerprint($this->users->passwordOf($user)),
            time()
        );

        return $token;
    }

    /**
     * @param array<string, mixed> $credentials ['email' => …, 'token' => …, 'password' => new password]
     * @return self::PASSWORD_RESET|self::INVALID_TOKEN
     */
    public function reset(array $credentials): string
    {
        $token    = $credentials['token'] ?? null;
        $password = $credentials['password'] ?? null;
        unset($credentials['token'], $credentials['password']);

        if (!is_string($token) || !is_string($password) || $password === '') {
            return self::INVALID_TOKEN;
        }

        $user = $this->users->retrieveByCredentials($credentials);
        if ($user === null || !$this->tokenIsValid($user, $token)) {
            return self::INVALID_TOKEN;
        }

        $this->users->updatePassword($user, $this->hasher->make($password));
        $this->tokens->delete((string) $this->users->idOf($user));

        return self::PASSWORD_RESET;
    }

    public function tokenIsValid(object $user, string $token): bool
    {
        $row = $this->tokens->find((string) $this->users->idOf($user));

        return $row !== null
            && $row['created_at'] + $this->ttl >= time()
            && hash_equals($row['token_hash'], hash('sha256', $token))
            && hash_equals($row['password_fingerprint'], self::fingerprint($this->users->passwordOf($user)));
    }

    private static function fingerprint(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }
}

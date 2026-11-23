<?php

namespace Luany\Framework\Auth;

use Luany\Framework\Contracts\SessionInterface;

/**
 * Session authentication.
 *
 *   if (auth()->attempt(['email' => $email, 'password' => $password])) {
 *       return redirect(auth()->intended('/painel'));
 *   }
 *   auth()->check();  auth()->user();  auth()->id();  auth()->logout();
 *
 * The session id is regenerated on login and logout (no fixation) and the
 * password is checked in constant time against a dummy hash when the user
 * does not exist (no user enumeration by timing).
 */
class AuthManager
{
    public const SESSION_KEY  = '_auth_user_id';
    public const INTENDED_KEY = '_auth_intended';

    private ?object $user = null;
    private bool $resolved = false;

    /** @var \Closure(): void|null Called after login/logout (rotates the CSRF token). */
    private ?\Closure $onChange = null;

    public function __construct(
        private SessionInterface $session,
        private UserProvider $provider,
        private Hasher $hasher,
    ) {
    }

    public function onSessionChange(\Closure $callback): void
    {
        $this->onChange = $callback;
    }

    /**
     * Log in with credentials (any columns + 'password'). False when they do
     * not match; nothing about which part failed is revealed.
     *
     * @param array<string, mixed> $credentials
     */
    public function attempt(array $credentials): bool
    {
        $user = $this->validate($credentials);
        if ($user === null) {
            return false;
        }

        $this->login($user);

        return true;
    }

    /**
     * The user for these credentials, without logging in.
     *
     * @param array<string, mixed> $credentials
     */
    public function validate(array $credentials): ?object
    {
        $password = $credentials['password'] ?? null;
        if (!is_string($password) || $password === '') {
            return null;
        }

        $user = $this->provider->retrieveByCredentials($credentials);
        $hash = $user !== null ? $this->provider->passwordOf($user) : self::dummyHash();

        if (!$this->hasher->check($password, $hash) || $user === null) {
            return null;
        }

        if ($this->hasher->needsRehash($hash)) {
            $this->provider->updatePassword($user, $this->hasher->make($password));
        }

        return $user;
    }

    public function login(object $user): void
    {
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, $this->provider->idOf($user));
        $this->user     = $user;
        $this->resolved = true;
        $this->changed();
    }

    public function logout(): void
    {
        $this->session->forget(self::SESSION_KEY);
        $this->session->regenerate();
        $this->user     = null;
        $this->resolved = true;
        $this->changed();
    }

    public function user(): ?object
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $id = $this->session->get(self::SESSION_KEY);
            $this->user = is_int($id) || is_string($id) ? $this->provider->retrieveById($id) : null;
        }

        return $this->user;
    }

    public function id(): int|string|null
    {
        $user = $this->user();

        return $user === null ? null : $this->provider->idOf($user);
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    /** Remember where a guest wanted to go (set by the Authenticate middleware). */
    public function setIntended(string $url): void
    {
        $this->session->set(self::INTENDED_KEY, $url);
    }

    /** The URL saved before the login redirect (local paths only), or $default. */
    public function intended(string $default = '/'): string
    {
        $url = $this->session->get(self::INTENDED_KEY);
        $this->session->forget(self::INTENDED_KEY);

        return is_string($url) && str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : $default;
    }

    public function hasher(): Hasher
    {
        return $this->hasher;
    }

    public function provider(): UserProvider
    {
        return $this->provider;
    }

    private function changed(): void
    {
        if ($this->onChange !== null) {
            ($this->onChange)();
        }
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
    }
}

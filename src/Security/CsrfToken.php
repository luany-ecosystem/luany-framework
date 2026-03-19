<?php

namespace Luany\Framework\Security;

use Luany\Framework\Contracts\SessionInterface;

/**
 * CsrfToken
 *
 * Generates and validates CSRF tokens using the session.
 * Tokens are stored in the session under the '_csrf_token' key.
 *
 * Usage:
 *   $csrf = new CsrfToken($session);
 *   $token = $csrf->token();          // get or generate token
 *   $csrf->validate($submitted);      // true/false
 *   $csrf->regenerate();              // force new token
 *
 * In forms:
 *   <input type="hidden" name="_token" value="{{ csrf_token() }}">
 */
class CsrfToken
{
    private const SESSION_KEY = '_csrf_token';

    public function __construct(private SessionInterface $session)
    {
    }

    /**
     * Get the current CSRF token, generating one if it does not exist.
     */
    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if ($token === null) {
            $token = $this->regenerate();
        }

        return $token;
    }

    /**
     * Validate a submitted token against the stored token.
     */
    public function validate(string $token): bool
    {
        $stored = $this->session->get(self::SESSION_KEY);

        if ($stored === null || $token === '') {
            return false;
        }

        return hash_equals($stored, $token);
    }

    /**
     * Generate a new CSRF token and store it in the session.
     *
     * @return string The new token
     */
    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }
}

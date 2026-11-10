<?php

namespace Luany\Framework\Security;

use Luany\Framework\Contracts\SessionInterface;

/**
 * CsrfToken
 *
 * Generates and validates CSRF tokens using the session.
 *
 * Ecosystem convention:
 *   form field  '_token'        (CsrfToken::FIELD; legacy 'csrf_token' accepted)
 *   session key '_csrf_token'   (CsrfToken::SESSION_KEY)
 *   header      'X-CSRF-TOKEN'  (CsrfToken::HEADER)
 *
 * The token is per session — it is NOT rotated on every request (that breaks
 * multiple tabs and back-button navigation). Call regenerate() on login and
 * logout, together with session()->regenerate().
 *
 * Usage:
 *   $csrf = new CsrfToken($session);
 *   $token = $csrf->token();          // get or generate token
 *   $csrf->validate($submitted);      // true/false (constant-time)
 *   $csrf->regenerate();              // force new token
 *
 * In forms:
 *   <input type="hidden" name="_token" value="{{ csrf_token() }}">
 */
class CsrfToken
{
    public const SESSION_KEY  = '_csrf_token';
    public const FIELD        = '_token';
    public const LEGACY_FIELD = 'csrf_token';
    public const HEADER       = 'X-CSRF-TOKEN';

    public function __construct(private SessionInterface $session)
    {
    }

    /**
     * Get the current CSRF token, generating one if it does not exist.
     */
    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = $this->regenerate();
        }

        return $token;
    }

    /**
     * Validate a submitted token against the stored token (constant-time).
     */
    public function validate(string $token): bool
    {
        $stored = $this->session->get(self::SESSION_KEY);

        if (!is_string($stored) || $stored === '' || $token === '') {
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

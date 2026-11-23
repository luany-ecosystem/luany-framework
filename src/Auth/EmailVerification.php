<?php

namespace Luany\Framework\Auth;

use Luany\Core\Http\Request;
use Luany\Framework\Security\SignedUrl;

/**
 * Email verification links.
 *
 *   $url = app('auth.verification')->url($user->id, $user->email);   // send by email
 *   // GET /email/verify?id=7&hash=…&expires=…&signature=…
 *   if (app('auth.verification')->verify($request, $user->id, $user->email)) { … mark verified … }
 *
 * The link is signed (APP_KEY), expires (1 h by default), and is bound to
 * the user id and to a hash of the current email: if the email changes, old
 * links stop working.
 */
final class EmailVerification
{
    public function __construct(
        private SignedUrl $signer,
        private string $path = '/email/verify',
        private int $ttl = 3600,
    ) {
    }

    public function url(int|string $userId, string $email): string
    {
        return $this->signer->sign($this->path, ['id' => (string) $userId, 'hash' => self::emailHash($email)], $this->ttl);
    }

    public function verify(Request $request, int|string $userId, string $currentEmail): bool
    {
        $id   = $request->query('id');
        $hash = $request->query('hash');

        return $this->signer->hasValidSignature($request)
            && is_string($id) && hash_equals((string) $userId, $id)
            && is_string($hash) && hash_equals(self::emailHash($currentEmail), $hash);
    }

    private static function emailHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}

<?php

namespace Luany\Framework\Security;

use Luany\Core\Http\Request;

/**
 * URLs signed with the APP_KEY (HMAC-SHA256).
 *
 *   $url = app('url.signer')->sign('/email/verify', ['id' => 7], expiresIn: 3600);
 *   app('url.signer')->hasValidSignature($request);
 *
 * The signature covers the path and every query parameter, including
 * `expires`; it is compared with hash_equals(). Rotating the APP_KEY
 * invalidates every URL already sent.
 */
final class SignedUrl
{
    private string $key;

    public function __construct(#[\SensitiveParameter] string $appKey)
    {
        $raw = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if (!is_string($raw) || strlen($raw) < 16) {
            throw new \RuntimeException('Signed URLs need an APP_KEY (run: luany key:generate).');
        }
        // A sub-key: the APP_KEY itself is never used directly for this.
        $this->key = hash_hmac('sha256', 'luany-signed-url:v1', $raw, true);
    }

    /**
     * @param array<string, scalar> $params
     */
    public function sign(string $path, array $params = [], ?int $expiresIn = null): string
    {
        unset($params['signature']);
        if ($expiresIn !== null) {
            $params['expires'] = time() + $expiresIn;
        }

        $query = self::query($params);
        $params['signature'] = $this->signature($path, $query);

        return $path . '?' . self::query($params);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function verify(string $path, array $query): bool
    {
        $signature = $query['signature'] ?? null;
        unset($query['signature']);

        if (!is_string($signature) || !self::scalars($query)) {
            return false;
        }
        if (isset($query['expires']) && (!is_numeric($query['expires']) || (int) $query['expires'] < time())) {
            return false;
        }

        /** @var array<string, scalar> $query */
        return hash_equals($this->signature($path, self::query($query)), $signature);
    }

    public function hasValidSignature(Request $request): bool
    {
        return $this->verify($request->uri(), $request->queryParams());
    }

    private function signature(string $path, string $query): string
    {
        return hash_hmac('sha256', $path . '?' . $query, $this->key);
    }

    /** @param array<string, scalar> $params */
    private static function query(array $params): string
    {
        ksort($params);

        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<mixed> $values */
    private static function scalars(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                return false;
            }
        }

        return true;
    }
}

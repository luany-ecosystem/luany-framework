<?php

namespace Luany\Framework\Support;

/**
 * Url
 *
 * Small URL helpers used for safe redirects.
 */
final class Url
{
    /**
     * Return $url when it points to the current host (or is a relative path),
     * otherwise $fallback. Prevents open redirects via the Referer header.
     *
     * Accepted:
     *   /users/create, /search?q=x               (relative, single leading slash)
     *   http(s)://{currentHost}/...               (same host and port)
     * Rejected:
     *   //evil.com, https://evil.com, javascript:..., https://host@evil.com,
     *   URLs with control characters or backslashes, unknown current host.
     *
     * @param string|null $url         Candidate URL (e.g. HTTP_REFERER)
     * @param string|null $currentHost Current Host header value (may include port)
     */
    public static function sameHostOrFallback(?string $url, ?string $currentHost, string $fallback = '/'): string
    {
        if ($url === null || $url === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $url)) {
            return $fallback;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $fallback;
        }

        $hasScheme = isset($parts['scheme']);
        $hasHost   = isset($parts['host']);

        // Relative path — must start with exactly one slash.
        if (!$hasScheme && !$hasHost) {
            return (str_starts_with($url, '/') && !str_starts_with($url, '//')) ? $url : $fallback;
        }

        if (!$hasScheme || !$hasHost || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return $fallback;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return $fallback;
        }

        if ($currentHost === null || $currentHost === '') {
            return $fallback;
        }

        $target = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $target === strtolower($currentHost) ? $url : $fallback;
    }
}

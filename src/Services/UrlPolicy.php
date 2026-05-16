<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

class UrlPolicy
{
    public static function trustedBaseUrl(string $url, string $label): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            throw new \RuntimeException("{$label} is not configured.");
        }

        if (! self::isTrustedHttpUrl($url) || self::hasQueryOrFragment($url)) {
            throw new \RuntimeException("{$label} must be an HTTPS URL outside local development.");
        }

        return $url;
    }

    public static function publicUrlOrNull(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        return self::isTrustedHttpUrl($url) ? $url : null;
    }

    public static function originOrNull(mixed $url): ?string
    {
        $url = self::publicUrlOrNull($url);
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private static function isTrustedHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(trim((string) $parts['host'], '[]'));

        return $scheme === 'https' || ($scheme === 'http' && self::isLocalHost($host));
    }

    private static function hasQueryOrFragment(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && (isset($parts['query']) || isset($parts['fragment']));
    }

    private static function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost');
    }
}

<?php declare(strict_types=1);

namespace Quermy\Http;

/**
 * Decides whether a request may reach the API at all, based on where it
 * claims to come from. Pure functions so they can be unit-tested; the front
 * controller feeds them the relevant $_SERVER values.
 *
 * Two different attacks are stopped here:
 *
 * - DNS rebinding. A website the user visits points its own domain at
 *   127.0.0.1 and then talks to a local Quermy as if it were its own site,
 *   connecting to local databases with default credentials. The browser
 *   still sends the attacker's domain in the Host header, so checking the
 *   Host header against a list of expected names defeats it.
 *
 * - Cross-origin requests. Another site (or another app on a different
 *   localhost port) makes the user's browser send requests that ride on the
 *   user's Quermy session. Browsers attach an Origin header to those, so a
 *   request whose Origin is not Quermy's own address is refused.
 */
final class RequestGuard
{
    /**
     * @param string        $hostHeader   Raw Host header.
     * @param string        $remoteAddr   Client IP as seen by PHP.
     * @param list<string>|null $allowedHosts  'allowed_hosts' from config.php, or
     *                                    null when not configured.
     */
    public static function hostAllowed(string $hostHeader, string $remoteAddr, ?array $allowedHosts): bool
    {
        $host = self::hostname($hostHeader);
        if ($host === '') {
            return false;
        }

        // Configured: the list is authoritative for every client.
        if ($allowedHosts !== null) {
            foreach ($allowedHosts as $allowed) {
                $allowed = strtolower(trim((string)$allowed));
                if ($allowed === $host) {
                    return true;
                }
                // "*.example.com" matches any subdomain (not the bare domain).
                if (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1))) {
                    return true;
                }
            }
            return false;
        }

        // Not configured. A client on another machine reached us by whatever
        // name the administrator published, which we cannot know — let it
        // through. Rebinding against a local install always arrives from the
        // local browser, so for loopback clients only accept names that
        // cannot be an outside domain.
        if (!self::isLoopbackAddress($remoteAddr)) {
            return true;
        }

        return self::isIpLiteral($host)
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || $host === strtolower((string)gethostname());
    }

    /**
     * @param string|null  $origin      Raw Origin header, null when absent.
     * @param string       $hostHeader  Raw Host header.
     * @param list<string> $devOrigins  'dev_cors_origins' from config.php.
     */
    public static function originAllowed(?string $origin, string $hostHeader, array $devOrigins): bool
    {
        // No Origin header: not a cross-origin browser request. Browsers send
        // Origin on every cross-origin request and on every non-GET request;
        // command-line clients send none and carry no victim's cookies.
        if ($origin === null || $origin === '') {
            return true;
        }

        if (self::isDevOrigin($origin, $devOrigins)) {
            return true;
        }

        // Same origin: the authority in Origin equals the Host we were
        // addressed by. The scheme is not compared because PHP cannot see it
        // reliably behind a TLS-terminating proxy. "null" origins (sandboxed
        // frames, some redirects) never match.
        if (!preg_match('#^https?://([^/]+)$#i', $origin, $m)) {
            return false;
        }

        return strtolower($m[1]) === strtolower(trim($hostHeader));
    }

    /**
     * @param list<string> $devOrigins
     */
    public static function isDevOrigin(?string $origin, array $devOrigins): bool
    {
        return $origin !== null && $origin !== '' && in_array($origin, $devOrigins, true);
    }

    /** Lower-cased hostname from a Host header, without port or IPv6 brackets' port. */
    private static function hostname(string $hostHeader): string
    {
        $h = strtolower(trim($hostHeader));
        if ($h === '') {
            return '';
        }
        if ($h[0] === '[') {
            // [::1] or [::1]:8000 — keep the bracketed address
            $end = strpos($h, ']');
            return $end === false ? '' : substr($h, 0, $end + 1);
        }
        $colon = strrpos($h, ':');
        return $colon === false ? $h : substr($h, 0, $colon);
    }

    private static function isIpLiteral(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private static function isLoopbackAddress(string $addr): bool
    {
        $addr = strtolower($addr);
        if (str_starts_with($addr, '::ffff:')) {
            $addr = substr($addr, 7);
        }
        return $addr === '::1' || str_starts_with($addr, '127.');
    }
}

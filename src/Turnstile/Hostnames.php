<?php

declare(strict_types=1);

namespace App\Turnstile;

/**
 * Cloudflare allows a Turnstile widget on the hostnames it lists *and their subdomains*:
 * listing example.com also covers www.example.com. These helpers use that to keep
 * the lists short.
 */
final class Hostnames
{
    /** True if $hostname is one of $hostnames or a subdomain of one. */
    public static function isCovered(string $hostname, array $hostnames): bool
    {
        $hostname = strtolower(rtrim($hostname, '.'));

        foreach ($hostnames as $candidate) {
            if ($hostname === $candidate || str_ends_with($hostname, '.'.$candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalises and de-duplicates hostnames and drops every hostname that a parent
     * in the same list already covers: [www.a.com, a.com, b.com] → [a.com, b.com].
     *
     * @param iterable<string> $hostnames
     *
     * @return list<string> sorted
     */
    public static function collapse(iterable $hostnames): array
    {
        $unique = [];
        foreach ($hostnames as $hostname) {
            $hostname = strtolower(trim(rtrim((string) $hostname, '.')));
            if ('' !== $hostname) {
                $unique[$hostname] = substr_count($hostname, '.');
            }
        }

        // Shortest (fewest labels) first, so parents are kept before their subdomains
        asort($unique);

        $kept = [];
        foreach (array_keys($unique) as $hostname) {
            if (!self::isCovered($hostname, $kept)) {
                $kept[] = $hostname;
            }
        }

        sort($kept);

        return $kept;
    }
}

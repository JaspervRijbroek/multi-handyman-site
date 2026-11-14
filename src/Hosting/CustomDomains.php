<?php

declare(strict_types=1);

namespace App\Hosting;

use App\Entity\Domain;
use App\Entity\SearchConsoleStatus;
use App\Entity\Tenant;
use App\Repository\DomainRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The hostnames of a client site:
 *
 *  - their platform subdomain, <slug>.CLIENT_SUBDOMAIN_BASE (online straight away), and
 *  - their own domain (example.com + www.example.com), added at sign-up or later in the admin.
 *
 * The own domain becomes the primary hostname by itself as soon as its DNS points to this
 * server (checked hourly by app:domains:check and whenever the client opens "Your domain").
 * Until then visitors keep using the subdomain, which then redirects, so the site is never offline.
 */
final class CustomDomains
{
    /** Subdomains a client can never get, so they don't look like part of the platform */
    private const array RESERVED = ['www', 'mail', 'admin', 'api', 'app'];

    /** A DNS label is at most 63 characters */
    public const int MAX_LABEL_LENGTH = 63;

    private readonly string $platformHost;
    private readonly string $subdomainBase;

    /** @var list<string>|null */
    private ?array $serverAddresses = null;

    public function __construct(
        private readonly DomainRepository $domains,
        #[Autowire(env: 'PLATFORM_HOST')]
        string $platformHost,
        #[Autowire(env: 'default::CLIENT_SUBDOMAIN_BASE')]
        ?string $subdomainBase,
    ) {
        $this->platformHost = strtolower($platformHost);
        $this->subdomainBase = strtolower(trim((string) $subdomainBase, ". \t"));
    }

    /** e.g. "jans-klusbedrijf.sites.example.com", or null when CLIENT_SUBDOMAIN_BASE is not set */
    public function subdomainFor(string $slug): ?string
    {
        return '' === $this->subdomainBase ? null : $slug.'.'.$this->subdomainBase;
    }

    /** Whether "<slug>.CLIENT_SUBDOMAIN_BASE" is free to use as a new client's subdomain */
    public function isSubdomainFree(string $slug): bool
    {
        $hostname = $this->subdomainFor($slug);

        return null === $hostname
            || (!\in_array($slug, self::RESERVED, true) && $hostname !== $this->platformHost && null === $this->domains->findOneByHostname($hostname));
    }

    public function isPlatformSubdomain(Domain $domain): bool
    {
        return '' !== $this->subdomainBase && str_ends_with($domain->getHostname(), '.'.$this->subdomainBase);
    }

    /** @return list<Domain> the client's own domain(s), without the platform subdomain */
    public function ownDomains(Tenant $tenant): array
    {
        return array_values(array_filter($tenant->getDomains()->toArray(), fn (Domain $d) => !$this->isPlatformSubdomain($d)));
    }

    /**
     * Why $hostname (already cleaned with Domain::clean()) cannot be added as someone's own domain.
     *
     * @return list<string> empty when it can
     */
    public function problems(string $hostname): array
    {
        if ('' === $hostname) {
            return ['Enter a domain name.'];
        }
        if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $hostname)) {
            return [\sprintf('"%s" is not a valid domain name, e.g. myhandyman.es.', $hostname)];
        }

        $unavailable = $this->unavailable($hostname);

        return null === $unavailable ? [] : [$unavailable];
    }

    /** Whether nobody uses $hostname (or its www.) yet and it is not one of the platform's own hostnames */
    public function isAvailable(string $hostname): bool
    {
        return null === $this->unavailable($hostname);
    }

    private function unavailable(string $hostname): ?string
    {
        $platform = (string) preg_replace('/^www\./', '', $this->platformHost);
        foreach (array_filter([$platform, $this->subdomainBase]) as $base) {
            if ($hostname === $base || str_ends_with($hostname, '.'.$base)) {
                return \sprintf('"%s" belongs to the platform, enter your own domain.', $hostname);
            }
        }

        foreach ([$hostname, 'www.'.$hostname] as $candidate) {
            if (null !== $this->domains->findOneByHostname($candidate)) {
                return \sprintf('"%s" is already used by another website.', $candidate);
            }
        }

        return null;
    }

    /**
     * Adds example.com and www.example.com. They become primary only once the DNS
     * points here (see updatePrimary()), unless the client has no hostname at all yet.
     */
    public function addOwnDomain(Tenant $tenant, string $hostname): void
    {
        $first = $tenant->getDomains()->isEmpty();
        $tenant->addDomain((new Domain())->setHostname($hostname)->setIsPrimary($first));
        $tenant->addDomain((new Domain())->setHostname('www.'.$hostname));
    }

    /** Removes the client's own domain(s); the platform subdomain takes over again. */
    public function removeOwnDomains(Tenant $tenant): void
    {
        $wasPrimary = $tenant->getPrimaryDomain();
        foreach ($this->ownDomains($tenant) as $domain) {
            $tenant->removeDomain($domain);
        }

        $primary = $tenant->getDomains()->first() ?: null;
        $primary?->setIsPrimary(true);
        if ($primary !== $wasPrimary) {
            $this->resetSearchConsole($tenant);
        }
    }

    /**
     * While the platform subdomain is the primary hostname, switches to the client's own
     * domain as soon as its DNS points here (example.com before www.example.com).
     * An own domain is never switched back automatically, so a DNS hiccup changes nothing.
     *
     * @return Domain|null the new primary hostname, or null when nothing changed
     */
    public function updatePrimary(Tenant $tenant): ?Domain
    {
        $current = $tenant->getPrimaryDomain();
        if (null === $current || !$this->isPlatformSubdomain($current) || [] === $this->serverAddresses()) {
            return null;
        }

        $candidates = $this->ownDomains($tenant);
        usort($candidates, static fn (Domain $a, Domain $b) => str_starts_with($a->getHostname(), 'www.') <=> str_starts_with($b->getHostname(), 'www.'));

        foreach ($candidates as $candidate) {
            if ($this->pointsHere($candidate->getHostname())) {
                foreach ($tenant->getDomains() as $domain) {
                    $domain->setIsPrimary($domain === $candidate);
                }
                $this->resetSearchConsole($tenant);

                return $candidate;
            }
        }

        return null;
    }

    /** True when every address of $hostname is one of this server's, false when not (yet). */
    public function pointsHere(string $hostname): bool
    {
        $found = $this->lookup($hostname);

        return [] !== $found && [] === array_diff($found, $this->serverAddresses());
    }

    /** @return list<string> the IPv4 and IPv6 addresses $hostname points to now */
    public function lookup(string $hostname): array
    {
        $ipv4 = @gethostbynamel($hostname) ?: [];
        $ipv6 = array_column(@dns_get_record($hostname, \DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }

    /**
     * Where client domains must point: the addresses of PLATFORM_HOST (the same server).
     * Empty when they cannot be looked up.
     *
     * @return list<string>
     */
    public function serverAddresses(): array
    {
        return $this->serverAddresses ??= $this->lookup($this->platformHost);
    }

    /**
     * The DNS records a client sets for their own domain.
     *
     * @return list<array{type: string, name: string, value: string}>
     */
    public function dnsRecords(): array
    {
        $records = [];
        foreach (['@', 'www'] as $name) {
            foreach ($this->serverAddresses() as $address) {
                $records[] = ['type' => str_contains($address, ':') ? 'AAAA' : 'A', 'name' => $name, 'value' => $address];
            }
        }

        return $records;
    }

    /** Search Console is set up for the primary hostname, so set it up again for the new one. */
    private function resetSearchConsole(Tenant $tenant): void
    {
        $tenant->setSearchConsoleToken(null);
        $tenant->setSearchConsoleStatus(SearchConsoleStatus::NotConnected, 'The website address changed, connecting it again.');
    }
}

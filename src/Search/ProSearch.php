<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Tenant;
use App\Entity\Trade;
use App\Map\Geocoder;
use App\Map\GeocodingException;
use App\Repository\TenantRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\String\UnicodeString;

/**
 * The "find a pro" search on the platform: filter clients by trade and sort
 * them by distance to a town, or by rating.
 *
 * Simple on purpose: all listed clients are loaded and filtered in PHP, which
 * is fast enough for hundreds of clients.
 */
final readonly class ProSearch
{
    /** Pros further away than this are left out of a search near a town */
    public const int MAX_DISTANCE_KM = 75;

    public const string SORT_DISTANCE = 'distance';
    public const string SORT_RATING = 'rating';

    public function __construct(
        private TenantRepository $tenants,
        private Geocoder $geocoder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     results: list<array{tenant: Tenant, km: ?float}>,
     *     located: bool,
     * } located is false when a town was given but could not be found
     *
     * @param LimiterInterface $lookups limits OpenStreetMap lookups for unknown towns (per visitor)
     */
    public function search(?Trade $trade, string $near, string $sort, LimiterInterface $lookups): array
    {
        $tenants = $this->tenants->findListed();

        if (null !== $trade) {
            $tenants = array_filter($tenants, static fn (Tenant $tenant) => $tenant->hasTrade($trade->value));
        }

        $point = '' === $near ? null : $this->locate($near, $tenants, $lookups);
        $results = [];
        foreach ($tenants as $tenant) {
            $km = null;
            if (null !== $point) {
                if (!$tenant->hasCoordinates()) {
                    continue;
                }
                $km = self::distanceKm($point[0], $point[1], $tenant->getLatitude(), $tenant->getLongitude());
                if ($km > self::MAX_DISTANCE_KM) {
                    continue;
                }
            }
            $results[] = ['tenant' => $tenant, 'km' => $km];
        }

        usort($results, static fn (array $a, array $b) => self::SORT_DISTANCE === $sort && null !== $point
            ? $a['km'] <=> $b['km']
            : [$b['tenant']->getReviewAverage() ?? 0, $b['tenant']->getReviewCount()] <=> [$a['tenant']->getReviewAverage() ?? 0, $a['tenant']->getReviewCount()]);

        return ['results' => $results, 'located' => '' === $near || null !== $point];
    }

    /** @return array<string, int> number of listed clients per trade value */
    public function tradeCounts(): array
    {
        $counts = array_fill_keys(Trade::values(), 0);
        foreach ($this->tenants->findListed() as $tenant) {
            foreach ($tenant->getTrades() as $trade) {
                if (isset($counts[$trade])) {
                    ++$counts[$trade];
                }
            }
        }

        return $counts;
    }

    /** @return list<string> towns where listed clients are based, for suggestions in the search box */
    public function towns(): array
    {
        $towns = array_unique(array_filter(array_map(
            static fn (Tenant $tenant) => $tenant->getCity(),
            $this->tenants->findListed(),
        )));
        sort($towns);

        return $towns;
    }

    /**
     * A town where clients are based is located without leaving our server;
     * anything else is looked up in OpenStreetMap.
     *
     * @param array<Tenant> $tenants
     *
     * @return array{float, float}|null
     */
    private function locate(string $near, array $tenants, LimiterInterface $lookups): ?array
    {
        foreach ($tenants as $tenant) {
            if ($tenant->hasCoordinates() && null !== $tenant->getCity() && self::sameTown($tenant->getCity(), $near)) {
                return [$tenant->getLatitude(), $tenant->getLongitude()];
            }
        }

        if (!$lookups->consume()->isAccepted()) {
            return null;
        }

        try {
            return $this->geocoder->locate($near);
        } catch (GeocodingException $e) {
            $this->logger->warning('Search could not locate "{place}": {message}', ['place' => $near, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /** "javea" matches "Jávea" */
    private static function sameTown(string $a, string $b): bool
    {
        $normalize = static fn (string $town) => (new UnicodeString($town))->ascii()->lower()->trim()->toString();

        return $normalize($a) === $normalize($b);
    }

    /** Straight-line distance (haversine formula) */
    private static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

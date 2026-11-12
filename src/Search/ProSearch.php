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

/**
 * The "find a pro" search on the platform: filter clients by trade, keep the
 * ones near a town and sort them by distance or rating. Filtering, sorting and
 * paging all happen in the database, so only one page of clients is loaded.
 */
final readonly class ProSearch
{
    /** Pros further away than this are left out of a search near a town */
    public const int MAX_DISTANCE_KM = 75;
    public const int PER_PAGE = 20;

    public const string SORT_DISTANCE = 'distance';
    public const string SORT_RATING = 'rating';

    /** Kilometres per degree of latitude */
    private const float KM_PER_DEGREE = 111.32;

    public function __construct(
        private TenantRepository $tenants,
        private Geocoder $geocoder,
        private TownIndex $townIndex,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param LimiterInterface $lookups limits OpenStreetMap lookups for unknown towns (per visitor)
     *
     * @return array{
     *     results: list<array{tenant: Tenant, km: ?float}>,
     *     total: int,
     *     pages: int,
     *     located: bool,
     *     town: ?string,
     * } located is false when a town was given but could not be found;
     *   town is the client town it was matched to when typed with a typo
     */
    public function search(?Trade $trade, string $near, string $sort, int $page, LimiterInterface $lookups): array
    {
        $qb = $this->tenants->createListedQueryBuilder('t');

        if (null !== $trade) {
            $qb->andWhere('t.trades LIKE :trade')->setParameter('trade', TenantRepository::tradePattern($trade->value));
        }

        [$point, $town] = '' === $near ? [null, null] : $this->locate($near, $lookups);
        if (null !== $point) {
            // Squared distance on a flat map: accurate to well under a kilometre at
            // these distances, and needs no trigonometry in the database
            $distance = '((t.latitude - :lat) * :kmLat) * ((t.latitude - :lat) * :kmLat)'
                .' + ((t.longitude - :lon) * :kmLon) * ((t.longitude - :lon) * :kmLon)';
            $qb->addSelect($distance.' AS HIDDEN distance')
                ->andWhere('t.latitude IS NOT NULL AND t.longitude IS NOT NULL')
                ->andWhere($distance.' <= :maxDistance')
                ->setParameter('lat', $point[0])
                ->setParameter('lon', $point[1])
                ->setParameter('kmLat', self::KM_PER_DEGREE)
                ->setParameter('kmLon', self::KM_PER_DEGREE * cos(deg2rad($point[0])))
                ->setParameter('maxDistance', self::MAX_DISTANCE_KM ** 2);
        }

        $total = (int) (clone $qb)->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

        if (null !== $point && self::SORT_DISTANCE === $sort) {
            $qb->orderBy('distance');
        } else {
            // MariaDB sorts NULL (no reviews yet) last in descending order
            $qb->orderBy('t.reviewAverage', 'DESC')->addOrderBy('t.reviewCount', 'DESC');
        }

        /** @var list<Tenant> $tenants */
        $tenants = $qb->addOrderBy('t.name')
            ->setFirstResult(($page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()
            ->getResult();
        $this->tenants->preloadDomains($tenants);

        return [
            'results' => array_map(static fn (Tenant $tenant) => [
                'tenant' => $tenant,
                'km' => null === $point ? null : self::distanceKm($point[0], $point[1], $tenant->getLatitude(), $tenant->getLongitude()),
            ], $tenants),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'located' => '' === $near || null !== $point,
            'town' => $town,
        ];
    }

    /** @return array<string, int> number of listed clients per trade value */
    public function tradeCounts(): array
    {
        return $this->tenants->countListedPerTrade(Trade::values());
    }

    /** @return list<string> towns where listed clients are based, for suggestions in the search box */
    public function towns(): array
    {
        return $this->tenants->findListedTowns();
    }

    /**
     * A town where clients are based is located without leaving our server, also
     * when typed with a typo (if OpenSearch is set up); anything else is looked
     * up in OpenStreetMap.
     *
     * @return array{?array{float, float}, ?string} the point, and the client town when corrected from a typo
     */
    private function locate(string $near, LimiterInterface $lookups): array
    {
        $known = $this->tenants->findTownCoordinates($near);
        if (null !== $known) {
            return [$known, null];
        }

        $town = $this->townIndex->find($near);
        $known = null === $town ? null : $this->tenants->findTownCoordinates($town);
        if (null !== $known) {
            return [$known, $town];
        }

        if (!$lookups->consume()->isAccepted()) {
            return [null, null];
        }

        try {
            return [$this->geocoder->locate($near), null];
        } catch (GeocodingException $e) {
            $this->logger->warning('Search could not locate "{place}": {message}', ['place' => $near, 'message' => $e->getMessage()]);

            return [null, null];
        }
    }

    /** Straight-line distance (haversine formula), for the "3 km" label */
    private static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

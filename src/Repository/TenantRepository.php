<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Domain;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tenant>
 */
class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    /**
     * Clients shown in the platform search: active, online and with a website address.
     */
    public function createListedQueryBuilder(string $alias = 't'): QueryBuilder
    {
        return $this->createQueryBuilder($alias)
            ->andWhere($alias.'.active = true')
            ->andWhere($alias.'.suspendedAt IS NULL')
            ->andWhere(\sprintf('EXISTS (SELECT d.id FROM %s d WHERE d.tenant = %s)', Domain::class, $alias));
    }

    /**
     * Coordinates of a town where a listed client is based. The database collation
     * ignores case and accents, so "javea" finds "Jávea".
     *
     * @return array{float, float}|null
     */
    public function findTownCoordinates(string $town): ?array
    {
        $row = $this->createListedQueryBuilder()
            ->select('t.latitude, t.longitude')
            ->andWhere('t.city = :town')
            ->andWhere('t.latitude IS NOT NULL AND t.longitude IS NOT NULL')
            ->setParameter('town', $town)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null === $row ? null : [(float) $row['latitude'], (float) $row['longitude']];
    }

    /** @return list<string> towns where listed clients are based */
    public function findListedTowns(): array
    {
        return $this->createListedQueryBuilder()
            ->select('DISTINCT t.city')
            ->andWhere('t.city IS NOT NULL')
            ->orderBy('t.city')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Number of listed clients per trade, in one query.
     *
     * @param list<string> $trades
     *
     * @return array<string, int>
     */
    public function countListedPerTrade(array $trades): array
    {
        $qb = $this->createListedQueryBuilder()->select('COUNT(t.id) AS total');
        foreach ($trades as $i => $trade) {
            $qb->addSelect(\sprintf('SUM(CASE WHEN t.trades LIKE :trade%1$d THEN 1 ELSE 0 END) AS trade%1$d', $i))
                ->setParameter('trade'.$i, self::tradePattern($trade));
        }
        $row = $qb->getQuery()->getSingleResult();

        $counts = [];
        foreach ($trades as $i => $trade) {
            $counts[$trade] = (int) $row['trade'.$i];
        }

        return $counts;
    }

    /**
     * Matches a trade in the JSON list in Tenant::$trades, e.g. ["handyman","pool"].
     * Trade values are fixed slugs, so a plain LIKE is reliable.
     */
    public static function tradePattern(string $trade): string
    {
        return '%"'.addcslashes($trade, '%_').'"%';
    }

    /**
     * Loads the domains of these clients in one query (instead of one per client).
     *
     * @param list<Tenant> $tenants
     */
    public function preloadDomains(array $tenants): void
    {
        if ([] === $tenants) {
            return;
        }

        $this->createQueryBuilder('t')
            ->addSelect('d')
            ->join('t.domains', 'd')
            ->andWhere('t IN (:tenants)')
            ->setParameter('tenants', $tenants)
            ->getQuery()
            ->getResult();
    }
}

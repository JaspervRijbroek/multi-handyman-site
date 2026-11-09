<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Review;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /** @return list<Review> */
    public function findLatestPublished(Tenant $tenant, int $limit): array
    {
        return $this->findBy(['tenant' => $tenant, 'published' => true], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /** Reviews waiting for the client to publish them (the tenant filter limits this to the active tenant). */
    public function countPending(): int
    {
        return $this->count(['published' => false]);
    }

    /** @return array{count: int, average: ?float} */
    public function publishedStats(int $tenantId): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('COUNT(r.id) AS reviewCount, AVG(r.rating) AS reviewAverage')
            ->andWhere('IDENTITY(r.tenant) = :tenant')
            ->andWhere('r.published = true')
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => (int) $row['reviewCount'],
            'average' => null === $row['reviewAverage'] ? null : round((float) $row['reviewAverage'], 1),
        ];
    }
}

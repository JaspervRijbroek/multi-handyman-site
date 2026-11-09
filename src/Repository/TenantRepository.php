<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     *
     * @return list<Tenant>
     */
    public function findListed(): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('d')
            ->join('t.domains', 'd')
            ->andWhere('t.active = true')
            ->andWhere('t.suspendedAt IS NULL')
            ->orderBy('t.name')
            ->getQuery()
            ->getResult();
    }
}

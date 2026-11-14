<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Charge;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Charge>
 */
class ChargeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Charge::class);
    }

    /** The charge that covers $day (a monthly one for that month, or a yearly one that includes it) */
    public function findCovering(Tenant $tenant, \DateTimeImmutable $day): ?Charge
    {
        $latest = $this->createQueryBuilder('c')
            ->where('c.tenant = :tenant')
            ->andWhere('c.period <= :day')
            ->setParameter('tenant', $tenant)
            ->setParameter('day', $day->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('c.period', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $latest?->covers($day->setTime(0, 0)) ? $latest : null;
    }

    /**
     * Charges whose automatic payment failed and that still have to be paid, oldest first.
     *
     * @return list<Charge>
     */
    public function findOutstanding(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant, 'status' => Charge::FAILED], ['period' => 'ASC']);
    }

    /** @return list<Charge> */
    public function findAllOutstanding(): array
    {
        return $this->findBy(['status' => Charge::FAILED], ['failedAt' => 'ASC', 'id' => 'ASC']);
    }

    /** @return list<Charge> */
    public function findAllPending(): array
    {
        return $this->findBy(['status' => Charge::PENDING]);
    }
}

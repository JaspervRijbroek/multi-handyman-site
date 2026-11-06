<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Charge;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function findForPeriod(Tenant $tenant, \DateTimeImmutable $period): ?Charge
    {
        return $this->findOneBy([
            'tenant' => $tenant,
            'period' => $period->modify('first day of this month')->setTime(0, 0),
        ]);
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

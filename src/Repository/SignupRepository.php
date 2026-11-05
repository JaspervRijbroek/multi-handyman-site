<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Signup;
use App\Entity\SignupStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Signup>
 */
class SignupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Signup::class);
    }

    public function countPending(): int
    {
        return $this->count(['status' => SignupStatus::Pending]);
    }

    /** @return list<Signup> */
    public function findPending(): array
    {
        return $this->findBy(['status' => SignupStatus::Pending], ['createdAt' => 'ASC']);
    }
}

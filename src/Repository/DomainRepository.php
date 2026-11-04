<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Domain;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Domain>
 */
class DomainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Domain::class);
    }

    public function findOneByHostname(string $hostname): ?Domain
    {
        return $this->createQueryBuilder('d')
            ->addSelect('t')
            ->join('d.tenant', 't')
            ->where('d.hostname = :hostname')
            ->setParameter('hostname', strtolower($hostname))
            ->getQuery()
            ->getOneOrNullResult();
    }
}

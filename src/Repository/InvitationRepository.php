<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invitation;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    /** An invitation that nobody signed up with yet */
    public function findOpenByToken(string $token): ?Invitation
    {
        $invitation = '' === $token ? null : $this->findOneBy(['token' => $token]);

        return $invitation?->isUsed() ? null : $invitation;
    }

    public function countFor(Tenant $inviter): int
    {
        return $this->count(['tenant' => $inviter]);
    }

    /** @return list<Invitation> newest first */
    public function findFor(Tenant $inviter): array
    {
        return $this->findBy(['tenant' => $inviter], ['createdAt' => 'DESC']);
    }
}

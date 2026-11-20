<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /** @return list<Project> The jobs shown on the website, newest first */
    public function findPublished(Tenant $tenant, ?int $limit = null): array
    {
        return $this->findBy(['tenant' => $tenant, 'published' => true], ['completedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}

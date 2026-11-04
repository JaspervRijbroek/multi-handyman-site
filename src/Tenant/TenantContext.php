<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Doctrine\TenantFilter;
use App\Entity\Tenant;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds the tenant of the current request:
 *  - on a client site: resolved from the hostname
 *  - in the admin: the tenant of the logged-in client (null for super admins)
 *
 * Setting a tenant switches on the Doctrine tenant filter, so from that moment
 * every query on tenant-owned entities only returns that tenant's rows.
 */
final class TenantContext implements ResetInterface
{
    private ?Tenant $tenant = null;

    // The registry (not the entity manager) avoids a circular dependency with Doctrine listeners
    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    public function set(Tenant $tenant): void
    {
        if (null === $tenant->getId()) {
            throw new \LogicException('Cannot activate a tenant that has not been saved yet.');
        }

        $this->tenant = $tenant;

        $this->entityManager()->getFilters()
            ->enable(TenantFilter::NAME)
            ->setParameter(TenantFilter::PARAMETER, $tenant->getId(), Types::INTEGER);
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function require(): Tenant
    {
        return $this->tenant ?? throw new \LogicException('No tenant is active for this request.');
    }

    public function has(): bool
    {
        return null !== $this->tenant;
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->doctrine->getManager();
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }

    public function reset(): void
    {
        $this->tenant = null;

        $filters = $this->entityManager()->getFilters();
        if ($filters->isEnabled(TenantFilter::NAME)) {
            $filters->disable(TenantFilter::NAME);
        }
    }
}

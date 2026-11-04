<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Marks an entity as belonging to one tenant (client).
 *
 * Every entity implementing this is automatically:
 *  - filtered to the current tenant in all queries (App\Doctrine\TenantFilter)
 *  - assigned to the current tenant on persist (App\EventListener\TenantGuardListener)
 *  - protected against cross-tenant writes (same listener) and reads in the admin (App\Security\TenantAccessVoter)
 */
interface TenantOwnedInterface
{
    public function getTenant(): ?Tenant;

    public function setTenant(?Tenant $tenant): static;
}

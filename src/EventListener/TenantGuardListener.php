<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\TenantOwnedInterface;
use App\Tenant\TenantContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Last line of defence: tenant-owned data can only be written for the active tenant.
 * New records get the active tenant automatically.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final readonly class TenantGuardListener
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TenantOwnedInterface) {
            return;
        }

        if (null === $entity->getTenant() && $this->tenantContext->has()) {
            $entity->setTenant($this->tenantContext->get());
        }

        $this->assertActiveTenant($entity);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof TenantOwnedInterface) {
            $this->assertActiveTenant($entity);
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof TenantOwnedInterface) {
            $this->assertActiveTenant($entity);
        }
    }

    private function assertActiveTenant(TenantOwnedInterface $entity): void
    {
        $active = $this->tenantContext->get();

        // No active tenant: console commands, fixtures or a super admin
        if (null === $active) {
            return;
        }

        if ($entity->getTenant()?->getId() !== $active->getId()) {
            throw new AccessDeniedException('Cross-tenant write blocked.');
        }
    }
}

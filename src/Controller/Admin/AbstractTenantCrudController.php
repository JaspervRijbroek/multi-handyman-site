<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tenant;
use App\Entity\TenantOwnedInterface;
use App\Entity\User;
use App\Security\TenantAccessVoter;
use App\Tenant\TenantContext;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Base for CRUD screens that clients use (services, projects, enquiries).
 *
 * Clients only ever see and edit their own records:
 *  - the Doctrine tenant filter limits all queries to their tenant
 *  - the entity permission (TenantAccessVoter) blocks opening other tenants' records by ID
 *  - new records are assigned to their tenant automatically
 *
 * Super admins see everything, plus a "Client" column/field.
 */
abstract class AbstractTenantCrudController extends AbstractCrudController
{
    protected TenantContext $tenantContext;

    #[Required]
    public function setTenantContext(TenantContext $tenantContext): void
    {
        $this->tenantContext = $tenantContext;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityPermission(TenantAccessVoter::ACCESS);
    }

    public function createEntity(string $entityFqcn): ?object
    {
        $entity = new $entityFqcn();

        if ($entity instanceof TenantOwnedInterface && $this->tenantContext->has()) {
            $entity->setTenant($this->tenantContext->require());
        }

        return $entity;
    }

    protected function isSuperAdmin(): bool
    {
        return $this->isGranted(User::ROLE_SUPER_ADMIN);
    }

    /** Languages to show inputs for: the client's own, or all of them for super admins. */
    protected function locales(): array
    {
        return $this->tenantContext->get()?->getLocales() ?? Tenant::SUPPORTED_LOCALES;
    }

    /** The "Client" field, only for super admins. */
    protected function tenantFields(): iterable
    {
        if ($this->isSuperAdmin()) {
            yield AssociationField::new('tenant', 'Client')->setRequired(true);
        }
    }
}

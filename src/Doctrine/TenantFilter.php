<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\TenantOwnedInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Adds "tenant_id = <current tenant>" to every SQL query on tenant-owned entities,
 * including find(), findBy(), DQL and lazy-loaded associations.
 */
final class TenantFilter extends SQLFilter
{
    public const string NAME = 'tenant';
    public const string PARAMETER = 'tenant_id';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!is_subclass_of($targetEntity->getName(), TenantOwnedInterface::class)) {
            return '';
        }

        if (!$this->hasParameter(self::PARAMETER)) {
            return '';
        }

        return \sprintf(
            '%s.%s = %s',
            $targetTableAlias,
            $targetEntity->getSingleAssociationJoinColumnName('tenant'),
            $this->getParameter(self::PARAMETER),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Tenant;
use App\Entity\TenantOwnedInterface;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants access to a tenant, or to a record owned by a tenant, only to that
 * tenant's own users (and to super admins). Used as EasyAdmin entity permission.
 *
 * @extends Voter<string, Tenant|TenantOwnedInterface>
 */
final class TenantAccessVoter extends Voter
{
    public const string ACCESS = 'TENANT_ACCESS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ACCESS === $attribute
            && ($subject instanceof Tenant || $subject instanceof TenantOwnedInterface);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $tenant = $subject instanceof Tenant ? $subject : $subject->getTenant();

        // A new record has no tenant yet; it gets the user's tenant on save
        if (null === $tenant) {
            return $subject instanceof TenantOwnedInterface;
        }

        return null !== $user->getTenant() && $user->getTenant()->getId() === $tenant->getId();
    }
}

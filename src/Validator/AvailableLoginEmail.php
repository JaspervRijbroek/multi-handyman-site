<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The email address of a sign-up must not belong to an existing login,
 * nor to another sign-up that is still waiting for approval.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class AvailableLoginEmail extends Constraint
{
    public string $loginExistsMessage = 'There is already an account with this email address. Log in instead, or use another address.';
    public string $pendingExistsMessage = 'We already received a sign-up with this email address. We will be in touch soon.';
}

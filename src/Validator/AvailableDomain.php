<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** The own domain of a sign-up must not be used by another website already. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class AvailableDomain extends Constraint
{
    public string $message = 'This domain is already used by another website. Check the spelling, or leave it empty and tell us in the message.';
}

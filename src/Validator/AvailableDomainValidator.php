<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\Signup;
use App\Hosting\CustomDomains;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class AvailableDomainValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CustomDomains $customDomains,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AvailableDomain) {
            throw new UnexpectedTypeException($constraint, AvailableDomain::class);
        }

        if (!\is_string($value) || '' === $value) {
            return;
        }

        // Already decided sign-ups are history (an approved one now owns the domain)
        $signup = $this->context->getObject();
        if ($signup instanceof Signup && !$signup->isPending()) {
            return;
        }

        if (!$this->customDomains->isAvailable($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}

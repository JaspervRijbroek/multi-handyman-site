<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\Signup;
use App\Entity\SignupStatus;
use App\Repository\SignupRepository;
use App\Repository\UserRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class AvailableLoginEmailValidator extends ConstraintValidator
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SignupRepository $signups,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AvailableLoginEmail) {
            throw new UnexpectedTypeException($constraint, AvailableLoginEmail::class);
        }

        if (!\is_string($value) || '' === $value) {
            return;
        }

        $signup = $this->context->getObject();

        // Already decided sign-ups are history; don't re-validate them
        if ($signup instanceof Signup && !$signup->isPending()) {
            return;
        }

        if (null !== $this->users->findOneBy(['email' => strtolower($value)])) {
            $this->context->buildViolation($constraint->loginExistsMessage)->addViolation();

            return;
        }

        $other = $this->signups->findOneBy(['email' => strtolower($value), 'status' => SignupStatus::Pending]);
        if (null !== $other && (!$signup instanceof Signup || $other->getId() !== $signup->getId())) {
            $this->context->buildViolation($constraint->pendingExistsMessage)->addViolation();
        }
    }
}

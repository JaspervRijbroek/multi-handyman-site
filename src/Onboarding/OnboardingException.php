<?php

declare(strict_types=1);

namespace App\Onboarding;

/** The sign-up cannot be approved as it is (e.g. the domain is taken). Nothing was saved. */
final class OnboardingException extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}

<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Entity\Tenant;

/** What onboarding did, step by step; shown in the console and as admin flash messages. */
final class OnboardingResult
{
    public const string OK = 'success';
    public const string WARNING = 'warning';
    public const string SKIPPED = 'info';
    public const string ERROR = 'danger';

    /** @var list<array{status: string, step: string, detail: string}> */
    private array $steps = [];

    public function __construct(
        public readonly Tenant $tenant,
    ) {
    }

    public function add(string $status, string $step, string $detail = ''): static
    {
        $this->steps[] = ['status' => $status, 'step' => $step, 'detail' => $detail];

        return $this;
    }

    /** @return list<array{status: string, step: string, detail: string}> */
    public function getSteps(): array
    {
        return $this->steps;
    }

    public function hasProblems(): bool
    {
        foreach ($this->steps as $step) {
            if (\in_array($step['status'], [self::WARNING, self::ERROR], true)) {
                return true;
            }
        }

        return false;
    }
}

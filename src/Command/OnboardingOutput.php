<?php

declare(strict_types=1);

namespace App\Command;

use App\Onboarding\OnboardingException;
use App\Onboarding\OnboardingResult;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Prints what onboarding did, shared by the onboarding commands. */
final class OnboardingOutput
{
    private const array ICONS = [
        OnboardingResult::OK => '<info>✔</info>',
        OnboardingResult::WARNING => '<comment>!</comment>',
        OnboardingResult::SKIPPED => '–',
        OnboardingResult::ERROR => '<error>✘</error>',
    ];

    public static function result(SymfonyStyle $io, OnboardingResult $result): void
    {
        $io->table(['', 'Step', 'Result'], array_map(
            static fn (array $step) => [self::ICONS[$step['status']] ?? '', $step['step'], wordwrap($step['detail'], 90)],
            $result->getSteps(),
        ));
    }

    public static function problems(SymfonyStyle $io, OnboardingException $e): void
    {
        $io->error(['Not approved. Fix these and try again:', ...$e->problems]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Onboarding\OnboardingException;
use App\Onboarding\OnboardingResult;

/** Shows what onboarding did as admin flash messages, one per step. */
trait OnboardingFlashesTrait
{
    private function flashResult(OnboardingResult $result): void
    {
        foreach ($result->getSteps() as $step) {
            // EasyAdmin renders flashes as raw HTML: escape everything
            $this->addFlash($step['status'], \sprintf(
                '<strong>%s</strong>%s',
                htmlspecialchars($step['step'], \ENT_QUOTES),
                '' !== $step['detail'] ? ': '.htmlspecialchars($step['detail'], \ENT_QUOTES) : '',
            ));
        }
    }

    private function flashProblems(OnboardingException $e): void
    {
        $this->addFlash(OnboardingResult::ERROR, '<strong>Not approved.</strong> Fix these and try again:<br>'.implode('<br>', array_map(
            static fn (string $problem) => htmlspecialchars($problem, \ENT_QUOTES),
            $e->problems,
        )));
    }
}

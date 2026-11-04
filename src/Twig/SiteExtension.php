<?php

declare(strict_types=1);

namespace App\Twig;

use App\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class SiteExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('localized', $this->localized(...)),
        ];
    }

    /**
     * Picks the text for the current language from a JSON text map, falling back
     * to the site's main language and then to any language that has text.
     *
     * Usage: {{ tenant.intro|localized }}
     *
     * @param array<string, string>|null $values
     */
    public function localized(?array $values, ?string $locale = null): string
    {
        if (!$values) {
            return '';
        }

        $locale ??= $this->requestStack->getCurrentRequest()?->getLocale();
        $fallback = $this->tenantContext->get()?->getDefaultLocale();

        return (string) ($values[$locale] ?? ($fallback ? $values[$fallback] ?? null : null) ?? reset($values));
    }
}

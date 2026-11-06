<?php

declare(strict_types=1);

namespace App\Twig;

use App\Tenant\TenantContext;
use App\Turnstile\Turnstile;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class SiteExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TenantContext $tenantContext,
        private readonly Turnstile $turnstile,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('localized', $this->localized(...)),
            new TwigFilter('money', self::money(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('turnstile_site_key', $this->turnstileSiteKey(...)),
        ];
    }

    /**
     * Site key of the Turnstile widget for this hostname, or null when Turnstile is off.
     *
     * Usage: {% if turnstile_site_key() %}<div class="cf-turnstile" data-sitekey="{{ turnstile_site_key() }}"></div>{% endif %}
     */
    public function turnstileSiteKey(): ?string
    {
        $host = $this->requestStack->getMainRequest()?->getHost();

        return $host ? $this->turnstile->keysFor($host)?->siteKey : null;
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

    /**
     * Formats an amount in cents. Usage: {{ charge.totalAmount|money(charge.currency) }} → €71.39
     */
    public static function money(int $cents, string $currency = 'EUR'): string
    {
        $amount = number_format($cents / 100, 2, '.', ',');

        return 'EUR' === $currency ? '€'.$amount : $currency.' '.$amount;
    }
}

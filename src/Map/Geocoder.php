<?php

declare(strict_types=1);

namespace App\Map;

use App\Entity\Tenant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Turns a client's address into map coordinates with Nominatim, the free
 * OpenStreetMap geocoder. No API key is needed.
 *
 * The public server's usage policy (https://operations.osmfoundation.org/policies/nominatim/)
 * asks for an identifying User-Agent and at most one request per second; both
 * are taken care of here. Onboarding only geocodes once per address, and the
 * platform search only asks for towns where no client works yet, which keeps
 * us well within that policy. For a self-hosted Nominatim, set NOMINATIM_URL.
 */
final class Geocoder
{
    private static float $lastRequestAt = 0.0;

    private readonly string $baseUrl;
    private readonly string $userAgent;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'default::NOMINATIM_URL')]
        ?string $baseUrl,
        #[Autowire(env: 'default::NOMINATIM_USER_AGENT')]
        ?string $userAgent,
        #[Autowire(env: 'PLATFORM_HOST')]
        string $platformHost,
    ) {
        $this->baseUrl = rtrim($baseUrl ?: 'https://nominatim.openstreetmap.org', '/');
        $this->userAgent = $userAgent ?: \sprintf('multi-handyman-site/1.0 (+https://%s)', $platformHost);
    }

    /**
     * Stores the coordinates on the tenant and returns the place as OpenStreetMap
     * knows it, or null if the address was not found at all.
     *
     * Clients who hide their street are located at town level only, so the
     * coordinates never point at their home.
     *
     * @throws GeocodingException when Nominatim cannot be reached
     */
    public function geocode(Tenant $tenant): ?GeocodeResult
    {
        if (!$tenant->hasAddress()) {
            return null;
        }

        $town = ['postalcode' => $tenant->getPostalCode(), 'city' => $tenant->getCity()];

        if ($tenant->isShowStreetAddress() && $tenant->getStreetAddress()) {
            $place = $this->search(['street' => $tenant->getStreetAddress(), ...$town], $tenant->getCountry(), $tenant->getDefaultLocale());
            if (null !== $place) {
                return $this->store($tenant, $place, exact: true);
            }
        }

        // Street unknown to OpenStreetMap (or hidden on purpose): the town is good enough for a map
        $place = $this->search($town, $tenant->getCountry(), $tenant->getDefaultLocale());

        return null === $place ? null : $this->store($tenant, $place, exact: false);
    }

    /**
     * Coordinates of a town or area typed into the platform search, e.g. "Jávea".
     *
     * @return array{float, float}|null [latitude, longitude], or null if the place is unknown
     *
     * @throws GeocodingException when Nominatim cannot be reached
     */
    public function locate(string $place, string $country = 'ES'): ?array
    {
        $found = $this->search(['q' => $place], $country, 'es');

        return null === $found ? null : [(float) $found['lat'], (float) $found['lon']];
    }

    /**
     * @param array<string, ?string> $address
     *
     * @return array{lat: string, lon: string, display_name?: string}|null
     */
    private function search(array $address, string $country, string $language): ?array
    {
        $this->respectRateLimit();

        try {
            $results = $this->httpClient->request('GET', $this->baseUrl.'/search', [
                'query' => [
                    ...array_filter($address),
                    'countrycodes' => strtolower($country),
                    'format' => 'jsonv2',
                    'limit' => 1,
                ],
                'headers' => [
                    'User-Agent' => $this->userAgent,
                    'Accept-Language' => $language,
                ],
                'timeout' => 10,
            ])->toArray();
        } catch (HttpExceptionInterface $e) {
            throw new GeocodingException('OpenStreetMap (Nominatim) could not be reached: '.$e->getMessage(), 0, $e);
        }

        return $results[0] ?? null;
    }

    /** @param array{lat: string, lon: string, display_name?: string} $place */
    private function store(Tenant $tenant, array $place, bool $exact): GeocodeResult
    {
        $tenant->setCoordinates((float) $place['lat'], (float) $place['lon']);

        return new GeocodeResult($place['display_name'] ?? $tenant->getPublicAddressLine(), $exact);
    }

    private function respectRateLimit(): void
    {
        $wait = self::$lastRequestAt + 1.0 - microtime(true);
        if ($wait > 0) {
            usleep((int) ceil($wait * 1_000_000));
        }
        self::$lastRequestAt = microtime(true);
    }
}

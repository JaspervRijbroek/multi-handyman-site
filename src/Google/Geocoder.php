<?php

declare(strict_types=1);

namespace App\Google;

use App\Entity\Tenant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Looks up the map position of a client's address with the Google Geocoding API.
 * Needs GOOGLE_MAPS_API_KEY with the Geocoding API enabled; without it the map
 * still works, it is just placed by address text instead of exact coordinates.
 */
final class Geocoder
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'default::GOOGLE_MAPS_API_KEY')]
        private readonly ?string $apiKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim((string) $this->apiKey);
    }

    /**
     * Stores the coordinates on the tenant. Returns the address as Google
     * understood it, or null if Google did not find it.
     *
     * @throws GoogleApiException
     */
    public function geocode(Tenant $tenant): ?string
    {
        if (!$tenant->hasAddress()) {
            return null;
        }

        try {
            $data = $this->httpClient->request('GET', 'https://maps.googleapis.com/maps/api/geocode/json', [
                'query' => [
                    'address' => $tenant->getAddressLine(),
                    'components' => 'country:'.$tenant->getCountry(),
                    'language' => $tenant->getDefaultLocale(),
                    'key' => $this->apiKey,
                ],
            ])->toArray();
        } catch (HttpExceptionInterface $e) {
            throw GoogleApiException::fromHttp('Google Geocoding failed', $e);
        }

        $status = $data['status'] ?? 'UNKNOWN_ERROR';
        if ('ZERO_RESULTS' === $status) {
            return null;
        }
        if ('OK' !== $status) {
            throw new GoogleApiException(\sprintf('Google Geocoding failed: %s %s', $status, $data['error_message'] ?? ''));
        }

        $result = $data['results'][0];
        $tenant->setCoordinates(
            (float) $result['geometry']['location']['lat'],
            (float) $result['geometry']['location']['lng'],
        );

        return $result['formatted_address'] ?? $tenant->getAddressLine();
    }
}

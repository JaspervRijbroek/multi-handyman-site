<?php

declare(strict_types=1);

namespace App\Billing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin wrapper around the parts of the Mollie REST API we use.
 *
 * @see https://docs.mollie.com/reference/overview
 */
final readonly class MollieClient
{
    private const string BASE_URL = 'https://api.mollie.com/v2/';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'MOLLIE_API_KEY')]
        private string $apiKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->apiKey;
    }

    /** @return array<string, mixed> */
    public function createCustomer(string $name, ?string $email, array $metadata = []): array
    {
        return $this->request('POST', 'customers', array_filter([
            'name' => $name,
            'email' => $email,
            'metadata' => $metadata,
        ]));
    }

    /**
     * @param array<string, mixed> $payment see https://docs.mollie.com/reference/create-payment
     *
     * @return array<string, mixed>
     */
    public function createPayment(array $payment): array
    {
        return $this->request('POST', 'payments', $payment);
    }

    /** @return array<string, mixed> */
    public function getPayment(string $paymentId): array
    {
        return $this->request('GET', 'payments/'.rawurlencode($paymentId));
    }

    /** @return array<string, mixed> */
    public function getMandate(string $customerId, string $mandateId): array
    {
        return $this->request('GET', \sprintf('customers/%s/mandates/%s', rawurlencode($customerId), rawurlencode($mandateId)));
    }

    /** @return list<array<string, mixed>> */
    public function listMandates(string $customerId): array
    {
        $response = $this->request('GET', \sprintf('customers/%s/mandates', rawurlencode($customerId)));

        return $response['_embedded']['mandates'] ?? [];
    }

    /** Mollie wants amounts as strings with two decimals, e.g. "71.39" */
    public static function amount(int $cents, string $currency): array
    {
        return ['currency' => $currency, 'value' => number_format($cents / 100, 2, '.', '')];
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, ?array $json = null): array
    {
        if (!$this->isConfigured()) {
            throw new MollieException('MOLLIE_API_KEY is not set.');
        }

        try {
            $response = $this->httpClient->request($method, self::BASE_URL.$path, array_filter([
                'auth_bearer' => $this->apiKey,
                'json' => $json,
                'timeout' => 15,
            ]));

            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new MollieException('Mollie request failed: '.$e->getMessage(), 0, $e);
        }

        if ($status >= 400) {
            throw new MollieException(\sprintf('Mollie returned %d: %s', $status, $data['detail'] ?? $data['title'] ?? 'unknown error'));
        }

        return $data;
    }
}

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

    /**
     * @param array<string, mixed> $subscription see https://docs.mollie.com/reference/create-subscription
     *
     * @return array<string, mixed>
     */
    public function createSubscription(string $customerId, array $subscription): array
    {
        return $this->request('POST', \sprintf('customers/%s/subscriptions', rawurlencode($customerId)), $subscription);
    }

    /** @return array<string, mixed> */
    public function getSubscription(string $customerId, string $subscriptionId): array
    {
        return $this->request('GET', self::subscriptionPath($customerId, $subscriptionId));
    }

    /**
     * @param array<string, mixed> $changes e.g. a new amount
     *
     * @return array<string, mixed>
     */
    public function updateSubscription(string $customerId, string $subscriptionId, array $changes): array
    {
        return $this->request('PATCH', self::subscriptionPath($customerId, $subscriptionId), $changes);
    }

    public function cancelSubscription(string $customerId, string $subscriptionId): void
    {
        $this->request('DELETE', self::subscriptionPath($customerId, $subscriptionId));
    }

    /** @return list<array<string, mixed>> the latest payments of the subscription, newest first */
    public function listSubscriptionPayments(string $customerId, string $subscriptionId, int $limit = 3): array
    {
        $response = $this->request('GET', self::subscriptionPath($customerId, $subscriptionId).'/payments?limit='.$limit);

        return $response['_embedded']['payments'] ?? [];
    }

    private static function subscriptionPath(string $customerId, string $subscriptionId): string
    {
        return \sprintf('customers/%s/subscriptions/%s', rawurlencode($customerId), rawurlencode($subscriptionId));
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

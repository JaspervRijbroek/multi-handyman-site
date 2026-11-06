<?php

declare(strict_types=1);

namespace App\Turnstile;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Turnstile widget endpoints of the Cloudflare API.
 * The API token needs the "Account > Turnstile > Edit" permission.
 *
 * @see https://developers.cloudflare.com/api/resources/turnstile/subresources/widgets/
 */
final readonly class CloudflareTurnstileApi
{
    private const string BASE_URL = 'https://api.cloudflare.com/client/v4/accounts/%s/challenges/widgets';

    /** Widget settings that a PUT would reset if we left them out */
    private const array KEPT_SETTINGS = ['mode', 'bot_fight_mode', 'clearance_level', 'offlabel', 'region', 'ephemeral_id'];

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'CLOUDFLARE_ACCOUNT_ID')]
        private string $accountId,
        #[Autowire(env: 'CLOUDFLARE_API_TOKEN')]
        private string $apiToken,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->accountId && '' !== $this->apiToken;
    }

    /**
     * @param list<string> $domains
     *
     * @return array{sitekey: string, secret: string}
     */
    public function createWidget(string $name, array $domains, string $mode): array
    {
        $widget = $this->request('POST', '', ['name' => $name, 'domains' => $domains, 'mode' => $mode]);

        if (!isset($widget['sitekey'], $widget['secret'])) {
            throw new TurnstileException('Cloudflare created a Turnstile widget but returned no keys.');
        }

        return $widget;
    }

    /** @return array<string, mixed>|null null if the widget no longer exists */
    public function getWidget(string $siteKey): ?array
    {
        return $this->request('GET', '/'.rawurlencode($siteKey), allowNotFound: true);
    }

    /**
     * Replaces the widget's hostname list. Name, mode and the other settings are
     * taken from $current (the result of getWidget()), so changes made in the
     * Cloudflare dashboard are kept.
     *
     * @param array<string, mixed> $current
     * @param list<string>         $domains
     */
    public function updateDomains(string $siteKey, array $current, array $domains): void
    {
        $body = ['name' => $current['name'] ?? 'Client sites', 'domains' => $domains];
        foreach (self::KEPT_SETTINGS as $setting) {
            if (\array_key_exists($setting, $current)) {
                $body[$setting] = $current[$setting];
            }
        }

        $this->request('PUT', '/'.rawurlencode($siteKey), $body);
    }

    public function deleteWidget(string $siteKey): void
    {
        $this->request('DELETE', '/'.rawurlencode($siteKey), allowNotFound: true);
    }

    /** @return array<string, mixed>|null */
    private function request(string $method, string $path, ?array $json = null, bool $allowNotFound = false): ?array
    {
        if (!$this->isConfigured()) {
            throw new TurnstileException('CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN are not set.');
        }

        try {
            $response = $this->httpClient->request($method, \sprintf(self::BASE_URL, rawurlencode($this->accountId)).$path, array_filter([
                'auth_bearer' => $this->apiToken,
                'json' => $json,
                'timeout' => 15,
            ]));

            $status = $response->getStatusCode();
            if (404 === $status && $allowNotFound) {
                return null;
            }

            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new TurnstileException('Cloudflare request failed: '.$e->getMessage(), 0, $e);
        }

        if ($status >= 400 || !($data['success'] ?? false)) {
            $errors = array_map(
                static fn (array $error): string => \sprintf('%s (%s)', $error['message'] ?? 'unknown error', $error['code'] ?? '?'),
                $data['errors'] ?? [],
            );

            throw new TurnstileException(\sprintf('Cloudflare returned %d: %s', $status, implode('; ', $errors) ?: 'unknown error'));
        }

        return $data['result'] ?? [];
    }
}

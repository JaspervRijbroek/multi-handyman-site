<?php

declare(strict_types=1);

namespace App\Turnstile;

use App\Repository\TurnstileWidgetRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cloudflare Turnstile ("are you human?") for the public forms.
 *
 * Which keys are used:
 *  - CLOUDFLARE_API_TOKEN set: the widget that lists the request's hostname,
 *    managed by TurnstileWidgetSync (production, one widget for many domains).
 *  - otherwise TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY (e.g. Cloudflare's test keys locally).
 *  - neither: Turnstile is off and forms work as before.
 *
 * @see https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 */
final class Turnstile
{
    public const string RESPONSE_FIELD = 'cf-turnstile-response';
    private const string VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @var array<string, TurnstileKeys|null> per hostname, for this request */
    private array $keys = [];

    public function __construct(
        private readonly CloudflareTurnstileApi $api,
        private readonly TurnstileWidgetRepository $widgets,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'TURNSTILE_SITE_KEY')]
        private readonly string $staticSiteKey,
        #[Autowire(env: 'TURNSTILE_SECRET_KEY')]
        private readonly string $staticSecretKey,
    ) {
    }

    /** null: no Turnstile on this hostname, the form works without it */
    public function keysFor(string $hostname): ?TurnstileKeys
    {
        $hostname = strtolower($hostname);

        return $this->keys[$hostname] ??= $this->resolveKeys($hostname);
    }

    /**
     * Checks the token the widget added to the submitted form.
     * Returns true when Turnstile is off for this hostname.
     */
    public function verify(Request $request, ?string $action = null): bool
    {
        $hostname = $request->getHost();
        $token = (string) $request->request->get(self::RESPONSE_FIELD);

        $keys = $this->keysFor($hostname);
        if (null === $keys) {
            return true;
        }

        if ('' === $token || \strlen($token) > 2048) {
            return false;
        }

        try {
            $result = $this->httpClient->request('POST', self::VERIFY_URL, [
                'body' => array_filter([
                    'secret' => $keys->secretKey,
                    'response' => $token,
                    'remoteip' => $request->getClientIp(),
                ]),
                'timeout' => 10,
            ])->toArray(false);
        } catch (ExceptionInterface $e) {
            // Cloudflare is unreachable: rather let a message through (the honeypot still
            // applies) than lose a customer's enquiry
            $this->logger->error('Turnstile verification failed, accepting the form: {message}', ['message' => $e->getMessage()]);

            return true;
        }

        if (!($result['success'] ?? false)) {
            $this->logger->info('Turnstile rejected a form on {host}: {codes}', [
                'host' => $hostname,
                'codes' => implode(', ', $result['error-codes'] ?? []),
            ]);

            return false;
        }

        // The token must come from the same form (our widgets set data-action; Cloudflare's
        // test keys return no action)
        return null === $action || '' === (string) ($result['action'] ?? '') || $result['action'] === $action;
    }

    private function resolveKeys(string $hostname): ?TurnstileKeys
    {
        if ($this->api->isConfigured()) {
            $widget = $this->widgets->findOneCovering($hostname);
            if (null === $widget) {
                // New domain that has not been synced yet (Cloudflare down?): the
                // honeypot keeps protecting the form until app:turnstile:sync has run
                $this->logger->warning('No Turnstile widget covers {host} yet, run app:turnstile:sync', ['host' => $hostname]);

                return null;
            }

            return new TurnstileKeys($widget->getSiteKey(), $widget->getSecretKey());
        }

        if ('' !== $this->staticSiteKey && '' !== $this->staticSecretKey) {
            return new TurnstileKeys($this->staticSiteKey, $this->staticSecretKey);
        }

        return null;
    }
}

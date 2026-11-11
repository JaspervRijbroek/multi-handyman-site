<?php

declare(strict_types=1);

namespace App\Google;

use App\Entity\SearchConsoleStatus;
use App\Entity\Tenant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sets up Google Search Console for a client site, fully automatically:
 *
 *  1. ask the Site Verification API for a META token, which the site then
 *     renders in <head> (templates/site/base.html.twig)
 *  2. once the site is online on its domain, let Google verify it; the
 *     service account becomes owner, plus the addresses in GOOGLE_OWNER_EMAILS
 *  3. add the property to Search Console and submit the sitemap
 *
 * Step 2 can only succeed after the client's DNS points to the server, so it is
 * retried by "app:google:connect --all" (the scheduler runs it hourly).
 */
final class SearchConsole
{
    private const array SCOPES = [
        'https://www.googleapis.com/auth/siteverification',
        'https://www.googleapis.com/auth/webmasters',
    ];

    private const string VERIFICATION_API = 'https://www.googleapis.com/siteVerification/v1';
    private const string WEBMASTERS_API = 'https://www.googleapis.com/webmasters/v3';

    public function __construct(
        private readonly GoogleServiceAccount $serviceAccount,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'default::GOOGLE_OWNER_EMAILS')]
        private readonly ?string $ownerEmails,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->serviceAccount->isConfigured();
    }

    /** URL-prefix property, e.g. https://example.com/ */
    public static function siteUrl(Tenant $tenant): ?string
    {
        $domain = $tenant->getPrimaryDomain();

        return $domain ? 'https://'.$domain->getHostname().'/' : null;
    }

    /**
     * Step 1. Returns true when a new token was stored on the tenant (flush it,
     * so the site serves it, before calling verifyAndRegister()).
     */
    public function ensureVerificationToken(Tenant $tenant): bool
    {
        if (null !== $tenant->getSearchConsoleToken()) {
            return false;
        }

        $siteUrl = self::siteUrl($tenant) ?? throw new GoogleApiException('The client has no domain yet.');

        try {
            $data = $this->httpClient->request('POST', self::VERIFICATION_API.'/token', [
                'auth_bearer' => $this->token(),
                'json' => [
                    'site' => ['type' => 'SITE', 'identifier' => $siteUrl],
                    'verificationMethod' => 'META',
                ],
            ])->toArray();
        } catch (HttpExceptionInterface $e) {
            throw GoogleApiException::fromHttp('Could not get a Search Console verification code', $e);
        }

        // The API returns the whole tag: <meta name="google-site-verification" content="..." />
        if (!preg_match('/content="([^"]+)"/', (string) ($data['token'] ?? ''), $match)) {
            throw new GoogleApiException('Google returned an unexpected verification token.');
        }

        $tenant->setSearchConsoleToken($match[1]);

        return true;
    }

    /**
     * Steps 2 and 3. Updates the Search Console status on the tenant and returns
     * a list of warnings that did not stop the setup.
     *
     * @return list<string>
     */
    public function verifyAndRegister(Tenant $tenant): array
    {
        $siteUrl = self::siteUrl($tenant);
        $token = $tenant->getSearchConsoleToken();
        if (null === $siteUrl || null === $token) {
            $tenant->setSearchConsoleStatus(SearchConsoleStatus::NotConnected, 'No domain or verification code yet.');

            return [];
        }

        // Ask Google only once we can see the tag ourselves: avoids pointless failed verifications
        if (null !== $problem = $this->checkSiteServesToken($siteUrl, $token)) {
            $tenant->setSearchConsoleStatus(SearchConsoleStatus::AwaitingSite, $problem);

            return [];
        }

        try {
            $resource = $this->httpClient->request('POST', self::VERIFICATION_API.'/webResource', [
                'auth_bearer' => $this->token(),
                'query' => ['verificationMethod' => 'META'],
                'json' => ['site' => ['type' => 'SITE', 'identifier' => $siteUrl]],
            ])->toArray();
        } catch (HttpExceptionInterface $e) {
            $tenant->setSearchConsoleStatus(SearchConsoleStatus::AwaitingSite, GoogleApiException::fromHttp('Google could not verify the site yet', $e)->getMessage());

            return [];
        }

        $warnings = [];
        if ($error = $this->addOwners($resource)) {
            $warnings[] = $error;
        }

        try {
            $this->httpClient->request('PUT', self::WEBMASTERS_API.'/sites/'.rawurlencode($siteUrl), [
                'auth_bearer' => $this->token(),
            ])->getHeaders();

            $sitemap = $siteUrl.'sitemap.xml';
            $this->httpClient->request('PUT', self::WEBMASTERS_API.'/sites/'.rawurlencode($siteUrl).'/sitemaps/'.rawurlencode($sitemap), [
                'auth_bearer' => $this->token(),
            ])->getHeaders();
        } catch (HttpExceptionInterface $e) {
            $tenant->setSearchConsoleStatus(SearchConsoleStatus::Failed, GoogleApiException::fromHttp('Verified, but adding the site to Search Console failed', $e)->getMessage());

            return $warnings;
        }

        $tenant->setSearchConsoleStatus(SearchConsoleStatus::Connected, \sprintf('Verified, property %s added and sitemap submitted.', $siteUrl));

        return $warnings;
    }

    private function checkSiteServesToken(string $siteUrl, string $token): ?string
    {
        try {
            $html = $this->httpClient->request('GET', $siteUrl, [
                'timeout' => 10,
                'max_redirects' => 3,
                'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; site-setup-check)'],
            ])->getContent();
        } catch (HttpExceptionInterface $e) {
            return \sprintf('%s is not reachable yet (%s). Point the domain\'s DNS to the server; this is retried automatically.', $siteUrl, $e->getMessage());
        }

        return str_contains($html, $token)
            ? null
            : \sprintf('%s is online but does not show the verification tag: is the DNS pointing to this server?', $siteUrl);
    }

    /**
     * Makes the people in GOOGLE_OWNER_EMAILS owners too, so the site shows up in
     * their own Search Console (the service account is not a person).
     *
     * @param array<string, mixed> $resource
     */
    private function addOwners(array $resource): ?string
    {
        $wanted = array_filter(array_map('trim', explode(',', strtolower((string) $this->ownerEmails))));
        $owners = array_map('strtolower', $resource['owners'] ?? []);
        $missing = array_diff($wanted, $owners);

        if ([] === $missing || !isset($resource['id'])) {
            return null;
        }

        try {
            $this->httpClient->request('PUT', self::VERIFICATION_API.'/webResource/'.rawurlencode($resource['id']), [
                'auth_bearer' => $this->token(),
                'json' => ['site' => $resource['site'], 'owners' => array_values(array_unique([...$owners, ...$missing]))],
            ])->getHeaders();
        } catch (HttpExceptionInterface $e) {
            return GoogleApiException::fromHttp('Could not add '.implode(', ', $missing).' as Search Console owner', $e)->getMessage();
        }

        return null;
    }

    private function token(): string
    {
        return $this->serviceAccount->getAccessToken(self::SCOPES);
    }
}

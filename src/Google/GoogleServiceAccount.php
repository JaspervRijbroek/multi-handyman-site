<?php

declare(strict_types=1);

namespace App\Google;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Access tokens for a Google Cloud service account (server-to-server OAuth,
 * https://developers.google.com/identity/protocols/oauth2/service-account).
 *
 * GOOGLE_SERVICE_ACCOUNT_KEY is the path to the JSON key file (absolute, or
 * relative to the project directory) or the JSON itself.
 */
final class GoogleServiceAccount
{
    /** @var array{client_email: string, private_key: string, token_uri: string}|null */
    private ?array $key = null;

    /** @var array<string, array{token: string, expires: int}> */
    private array $tokens = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'default::GOOGLE_SERVICE_ACCOUNT_KEY')]
        private readonly ?string $keyConfig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim((string) $this->keyConfig);
    }

    /** E-mail address of the service account; it becomes the owner of the Search Console properties. */
    public function getEmail(): string
    {
        return $this->key()['client_email'];
    }

    /**
     * @param list<string> $scopes
     *
     * @throws GoogleApiException
     */
    public function getAccessToken(array $scopes): string
    {
        $cacheKey = implode(' ', $scopes);
        if (isset($this->tokens[$cacheKey]) && $this->tokens[$cacheKey]['expires'] > time() + 60) {
            return $this->tokens[$cacheKey]['token'];
        }

        $key = $this->key();
        $now = time();
        $assertion = $this->signJwt([
            'iss' => $key['client_email'],
            'scope' => $cacheKey,
            'aud' => $key['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ], $key['private_key']);

        try {
            $data = $this->httpClient->request('POST', $key['token_uri'], [
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ],
            ])->toArray();
        } catch (HttpExceptionInterface $e) {
            throw GoogleApiException::fromHttp('Could not log in to Google with the service account', $e);
        }

        $this->tokens[$cacheKey] = ['token' => $data['access_token'], 'expires' => $now + (int) ($data['expires_in'] ?? 3600)];

        return $data['access_token'];
    }

    /** @param array<string, mixed> $claims */
    private function signJwt(array $claims, string $privateKey): string
    {
        $segments = [
            self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], \JSON_THROW_ON_ERROR)),
            self::base64Url(json_encode($claims, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)),
        ];

        if (!openssl_sign(implode('.', $segments), $signature, $privateKey, \OPENSSL_ALGO_SHA256)) {
            throw new GoogleApiException('The private key in GOOGLE_SERVICE_ACCOUNT_KEY could not be used to sign.');
        }
        $segments[] = self::base64Url($signature);

        return implode('.', $segments);
    }

    /** @return array{client_email: string, private_key: string, token_uri: string} */
    private function key(): array
    {
        if (null !== $this->key) {
            return $this->key;
        }

        $config = trim((string) $this->keyConfig);
        if ('' === $config) {
            throw new GoogleApiException('GOOGLE_SERVICE_ACCOUNT_KEY is not set.');
        }

        if (!str_starts_with($config, '{')) {
            $path = str_starts_with($config, '/') ? $config : $this->projectDir.'/'.$config;
            $json = is_readable($path) ? file_get_contents($path) : false;
            if (false === $json) {
                throw new GoogleApiException(\sprintf('Cannot read the Google service account key file "%s".', $path));
            }
            $config = $json;
        }

        $key = json_decode($config, true);
        if (!\is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
            throw new GoogleApiException('GOOGLE_SERVICE_ACCOUNT_KEY is not a valid service account key (JSON with client_email and private_key).');
        }

        return $this->key = [
            'client_email' => $key['client_email'],
            'private_key' => $key['private_key'],
            'token_uri' => $key['token_uri'] ?? 'https://oauth2.googleapis.com/token',
        ];
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

<?php

declare(strict_types=1);

namespace App\Search;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds a town where clients are based even when it is typed with a typo
 * ("Javae" → "Jávea"), using an OpenSearch index of town names.
 *
 * Optional: without OPENSEARCH_URL, or when OpenSearch can't be reached, find()
 * returns null and the search carries on with OpenStreetMap as before.
 * app:search:index (re)builds the index; run it from cron.
 */
final readonly class TownIndex
{
    private const string INDEX = 'towns';

    private string $baseUrl;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire(env: 'default::OPENSEARCH_URL')]
        ?string $baseUrl,
    ) {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
    }

    public function isEnabled(): bool
    {
        return '' !== $this->baseUrl;
    }

    /** The indexed town closest to what was typed, or null if none is close enough */
    public function find(string $typed): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $result = $this->httpClient->request('POST', $this->url('/_search'), [
                'json' => [
                    'size' => 1,
                    'query' => ['match' => ['name' => [
                        'query' => $typed,
                        'fuzziness' => 'AUTO',
                        'operator' => 'and',
                    ]]],
                ],
                'timeout' => 2,
            ])->toArray();
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning('OpenSearch could not be reached: {message}', ['message' => $e->getMessage()]);

            return null;
        }

        return $result['hits']['hits'][0]['_source']['name'] ?? null;
    }

    /**
     * Replaces the index with these towns.
     *
     * @param list<string> $towns
     *
     * @throws HttpExceptionInterface when OpenSearch can't be reached or refuses the request
     */
    public function rebuild(array $towns): void
    {
        $this->httpClient->request('DELETE', $this->url(), ['query' => ['ignore_unavailable' => 'true']])->getContent();

        // Lowercase and without accents, so "javea" matches "Jávea"
        $this->httpClient->request('PUT', $this->url(), ['json' => [
            'settings' => ['analysis' => ['analyzer' => ['town' => [
                'tokenizer' => 'standard',
                'filter' => ['lowercase', 'asciifolding'],
            ]]]],
            'mappings' => ['properties' => ['name' => ['type' => 'text', 'analyzer' => 'town']]],
        ]])->getContent();

        if ([] === $towns) {
            return;
        }

        $bulk = '';
        foreach ($towns as $town) {
            $bulk .= "{\"index\":{}}\n".json_encode(['name' => $town], \JSON_THROW_ON_ERROR)."\n";
        }
        $this->httpClient->request('POST', $this->url('/_bulk'), [
            'query' => ['refresh' => 'true'],
            'headers' => ['Content-Type' => 'application/x-ndjson'],
            'body' => $bulk,
        ])->getContent();
    }

    private function url(string $path = ''): string
    {
        return $this->baseUrl.'/'.self::INDEX.$path;
    }
}

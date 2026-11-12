<?php

declare(strict_types=1);

namespace App\Billing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin wrapper around the parts of the FacturaDirecta API we use (invoices for our clients).
 *
 * @see https://github.com/facturadirecta/api-docs
 */
final readonly class FacturaDirectaClient
{
    private const string BASE_URL = 'https://app.facturadirecta.com/api/';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'FACTURADIRECTA_API_KEY')]
        private string $apiKey,
        #[Autowire(env: 'FACTURADIRECTA_COMPANY_ID')]
        private string $companyId,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->apiKey && '' !== $this->companyId;
    }

    /**
     * @param array<string, mixed> $main see "Crear contacto" in the API docs
     *
     * @return array<string, mixed> the contact, with its id in content.uuid
     */
    public function createContact(array $main, string $idempotencyKey): array
    {
        return $this->request('POST', 'contacts', ['content' => ['type' => 'contact', 'main' => $main]], $idempotencyKey);
    }

    /**
     * @param array<string, mixed> $main see "Crear factura" in the API docs
     *
     * @return array<string, mixed> the invoice, with its id in content.uuid and its number in content.main.docNumber
     */
    public function createInvoice(array $main, string $idempotencyKey): array
    {
        return $this->request('POST', 'invoices', ['content' => ['type' => 'invoice', 'main' => $main]], $idempotencyKey);
    }

    /** @return array<string, mixed> e.g. content.numbering: how invoice numbers are printed */
    public function getCompanySettings(): array
    {
        return $this->request('GET', 'settings/company');
    }

    /**
     * The invoice number as printed on the invoice, e.g. "F-26-0012".
     *
     * @param array<string, mixed> $invoice   as returned by createInvoice()
     * @param array<string, mixed> $numbering content.numbering of the company settings: {zeros, position}
     */
    public static function invoiceNumber(array $invoice, array $numbering): string
    {
        $main = $invoice['content']['main'] ?? [];
        $docNumber = $main['docNumber'] ?? null;
        if (\is_string($docNumber)) {
            return $docNumber;
        }

        // "##" / "####" in a series stand for the year of the invoice
        $date = new \DateTimeImmutable($main['date'] ?? 'now');
        $series = $docNumber['formattedSeries']
            ?? str_replace(['####', '##'], [$date->format('Y'), $date->format('y')], (string) ($docNumber['series'] ?? ''));
        $number = str_pad((string) ($docNumber['number'] ?? ''), (int) ($numbering['zeros'] ?? 0), '0', \STR_PAD_LEFT);

        return 'suffix' === ($numbering['position'] ?? 'prefix') ? $number.$series : $series.$number;
    }

    /**
     * @param string|null $idempotencyKey a retry with the same key never creates a second record (kept for 24 hours)
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $json = null, ?string $idempotencyKey = null): array
    {
        if (!$this->isConfigured()) {
            throw new FacturaDirectaException('FACTURADIRECTA_API_KEY or FACTURADIRECTA_COMPANY_ID is not set.');
        }

        try {
            $response = $this->httpClient->request($method, self::BASE_URL.rawurlencode($this->companyId).'/'.$path, array_filter([
                'headers' => array_filter([
                    'facturadirecta-api-key' => $this->apiKey,
                    'Idempotency-Key' => $idempotencyKey,
                ]),
                'json' => $json,
                'timeout' => 30,
            ]));

            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new FacturaDirectaException('FacturaDirecta request failed: '.$e->getMessage(), 0, $e);
        }

        if ($status >= 400) {
            $details = array_map(static fn (array $error) => ($error['path'] ?? '').': '.($error['message'] ?? ''), $data['errors'] ?? []);

            throw new FacturaDirectaException(\sprintf('FacturaDirecta returned %d: %s', $status, implode('; ', [$data['message'] ?? 'unknown error', ...$details])));
        }

        return $data;
    }
}

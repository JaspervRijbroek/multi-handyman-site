<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Charge;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates the invoice (factura) for a monthly charge in FacturaDirecta, before the
 * payment is sent to Mollie, so the payment can carry the invoice number.
 *
 * Without FacturaDirecta settings this is skipped with a warning (e.g. locally).
 * A FacturaDirecta error stops the charge; the billing queue and the daily run retry it.
 */
final readonly class Invoicing
{
    public function __construct(
        private FacturaDirectaClient $facturaDirecta,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        #[Autowire(env: 'FACTURADIRECTA_SERIES')]
        private string $series,
        #[Autowire(env: 'FACTURADIRECTA_TAX')]
        private string $tax,
        #[Autowire(env: 'FACTURADIRECTA_ACCOUNT')]
        private string $account,
        #[Autowire(env: 'BILLING_BRAND')]
        private string $brand,
    ) {
    }

    /** Creates the invoice unless the charge already has one */
    public function invoice(Charge $charge): void
    {
        if (null !== $charge->getInvoiceNumber()) {
            return;
        }

        if (!$this->facturaDirecta->isConfigured()) {
            $this->logger->warning('FacturaDirecta is not configured: no invoice for {tenant}, {period}.', [
                'tenant' => $charge->getTenant()->getSlug(),
                'period' => $charge->getPeriodLabel(),
            ]);

            return;
        }

        // Read first: once the invoice exists, nothing may fail before its number is stored
        $numbering = $this->facturaDirecta->getCompanySettings()['content']['numbering'] ?? [];
        $contactId = $this->ensureContact($charge->getTenant());
        $net = $charge->getNetAmount() / 100;
        $today = new \DateTimeImmutable();

        $invoice = $this->facturaDirecta->createInvoice([
            'contact' => $contactId,
            'date' => $today->format('Y-m-d'),
            'dueDate' => $today->format('Y-m-d'), // collected straight away
            'currency' => $charge->getCurrency(),
            'docNumber' => ['series' => $this->series], // FacturaDirecta assigns the next number
            'fiscalPosition' => 'general',
            'account' => $this->account,
            'draft' => false,
            'voided' => false,
            'simplified' => false,
            'lines' => [[
                'account' => $this->account,
                'quantity' => 1,
                'unitPrice' => $net,
                'discount' => 0,
                'discountRate' => 0,
                'lineTotal' => $net,
                'tax' => [$this->tax],
                'text' => $this->brand.' – '.$charge->getPeriodLabel(),
            ]],
        ], 'billing-charge-'.$charge->getId().'-'.$today->format('Ymd')); // same body on a same-day retry

        $charge->setInvoice(
            $invoice['content']['uuid'] ?? throw new FacturaDirectaException('FacturaDirecta returned no invoice id.'),
            FacturaDirectaClient::invoiceNumber($invoice, $numbering),
        );
        $this->entityManager->flush();
    }

    /** The client as a contact in FacturaDirecta, created on their first invoice */
    private function ensureContact(Tenant $tenant): string
    {
        if (null === $tenant->getFacturaDirectaContactId()) {
            $contact = $this->facturaDirecta->createContact(array_filter([
                'name' => $tenant->getLegalName() ?: $tenant->getName(),
                'email' => $tenant->getBillingRecipient(),
                'fiscalId' => $tenant->getTaxId(),
                'address' => $tenant->getLegalAddress() ?: $tenant->getStreetAddress(),
                'zipcode' => $tenant->getPostalCode(),
                'city' => $tenant->getCity(),
                'country' => $tenant->getCountry(),
            ]), 'billing-tenant-'.$tenant->getId());

            $tenant->setFacturaDirectaContactId($contact['content']['uuid'] ?? throw new FacturaDirectaException('FacturaDirecta returned no contact id.'));
            $this->entityManager->flush();
        }

        return $tenant->getFacturaDirectaContactId();
    }
}

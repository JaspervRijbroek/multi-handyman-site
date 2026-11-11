<?php

declare(strict_types=1);

namespace App\Billing;

use App\Repository\TenantRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles the billing queue, one message at a time (see config/packages/messenger.yaml).
 * An exception (e.g. Mollie unreachable) makes the queue retry the message later.
 */
final readonly class BillingMessageHandler
{
    public function __construct(
        private BillingService $billing,
        private TenantRepository $tenants,
    ) {
    }

    #[AsMessageHandler]
    public function charge(ChargeClient $message): void
    {
        $tenant = $this->tenants->find($message->tenantId);
        if (null !== $tenant) {
            $this->billing->chargeIfDue($tenant, new \DateTimeImmutable($message->day));
        }
    }

    #[AsMessageHandler]
    public function sync(SyncPayment $message): void
    {
        $this->billing->syncPayment($message->paymentId);
    }
}

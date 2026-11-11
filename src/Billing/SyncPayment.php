<?php

declare(strict_types=1);

namespace App\Billing;

/** Queued by the Mollie webhook and app:billing:run: fetch a payment's status from Mollie */
final readonly class SyncPayment
{
    public function __construct(
        public string $paymentId,
    ) {
    }
}

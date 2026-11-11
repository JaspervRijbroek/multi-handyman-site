<?php

declare(strict_types=1);

namespace App\Billing;

/** Queued by app:billing:run: fetch a client's subscription and its latest payments from Mollie */
final readonly class SyncSubscription
{
    public function __construct(
        public int $tenantId,
    ) {
    }
}

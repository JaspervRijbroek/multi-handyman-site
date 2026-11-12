<?php

declare(strict_types=1);

namespace App\Billing;

/** Queued by app:billing:run: charge one client for the month of $day (Y-m-d) */
final readonly class ChargeClient
{
    public function __construct(
        public int $tenantId,
        public string $day,
    ) {
    }
}

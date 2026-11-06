<?php

declare(strict_types=1);

namespace App\Turnstile;

/** The site key (public, in the page) and secret key (server side) of one widget. */
final readonly class TurnstileKeys
{
    public function __construct(
        public string $siteKey,
        public string $secretKey,
    ) {
    }
}

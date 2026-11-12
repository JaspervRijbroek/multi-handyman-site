<?php

declare(strict_types=1);

namespace App\Review;

/** What ReviewRequestSender did, so the admin can tell the client. */
final readonly class ReviewRequestResult
{
    public const EMAILED = 'emailed';
    public const EMAIL_FAILED = 'email_failed';
    /** The client sends the link themselves, with $whatsappUrl */
    public const WHATSAPP = 'whatsapp';
    /** The client has no website domain yet, so there is nowhere to link to */
    public const NO_DOMAIN = 'no_domain';

    public function __construct(
        public string $status,
        public ?string $link = null,
        public ?string $whatsappUrl = null,
    ) {
    }
}

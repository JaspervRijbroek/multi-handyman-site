<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Progress of the automatic Google Search Console setup for a client site. */
enum SearchConsoleStatus: string implements TranslatableInterface
{
    /** Nothing done yet */
    case NotConnected = 'not_connected';
    /** Verification tag is on the site, but Google could not reach it yet (DNS not pointed, no HTTPS yet) */
    case AwaitingSite = 'awaiting_site';
    /** Verified, property added and sitemap submitted */
    case Connected = 'connected';
    /** Google refused; see the message */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::NotConnected => 'Not connected',
            self::AwaitingSite => 'Waiting for the site to be online',
            self::Connected => 'Connected',
            self::Failed => 'Failed',
        };
    }

    /** Shows the label in the admin (EasyAdmin renders translatable enums with it) */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}

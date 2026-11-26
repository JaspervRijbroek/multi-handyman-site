<?php

declare(strict_types=1);

namespace App\Site;

use App\Entity\Enquiry;
use App\Entity\Review;
use App\Entity\Tenant;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells a client about a new enquiry or review on their website, in the client's
 * email language. Never throws: the enquiry or review is saved in the admin either way.
 */
final readonly class SiteMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
        #[Autowire(env: 'BILLING_BRAND')]
        private string $brand,
    ) {
    }

    public function notifyEnquiry(Tenant $tenant, Enquiry $enquiry, string $host): void
    {
        $this->send($tenant, 'enquiry', ['%name%' => $enquiry->getName(), '%host%' => $host], [
            'enquiry' => $enquiry,
            'host' => $host,
        ], $enquiry->getContactEmail());
    }

    public function notifyReview(Tenant $tenant, Review $review, string $host): void
    {
        $name = $review->isAnonymous()
            ? $this->translator->trans('review.anonymous', [], 'email', $tenant->getEmailLocale())
            : $review->getAuthorName();

        $this->send($tenant, 'review', ['%name%' => $name], [
            'review' => $review,
            'project' => $review->getProject(),
            'host' => $host,
        ]);
    }

    /** @param array<string, string> $subjectParameters */
    private function send(Tenant $tenant, string $template, array $subjectParameters, array $context, ?string $replyTo = null): void
    {
        if (!$tenant->getEmail()) {
            return;
        }

        $locale = $tenant->getEmailLocale();
        $email = (new TemplatedEmail())
            ->from(new Address($this->from, $this->brand))
            ->to($tenant->getEmail())
            ->locale($locale)
            ->subject($this->translator->trans($template.'.subject', $subjectParameters, 'email', $locale))
            ->htmlTemplate(\sprintf('email/%s.html.twig', $template))
            ->context([...$context, 'tenant' => $tenant]);

        if ($replyTo) {
            $email->replyTo($replyTo);
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Could not send notification email: {message}', ['message' => $e->getMessage()]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Review;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends the customer of a project a link to review the job on the client's website.
 *
 * Email addresses get the link by email. For phone numbers we have no SMS service:
 * the client sends the link themselves with a prepared WhatsApp message.
 */
final readonly class ReviewRequestSender
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
    ) {
    }

    /**
     * Creates a new review link (valid Project::REVIEW_LINK_DAYS days) and emails it if
     * the customer gave an email address. Returns what the client should see.
     */
    public function send(Project $project): ReviewRequestResult
    {
        $tenant = $project->getTenant();
        $domain = $tenant?->getPrimaryDomain();
        if (!$tenant || !$domain) {
            return new ReviewRequestResult(ReviewRequestResult::NO_DOMAIN);
        }

        $token = $project->startReviewRequest();
        $this->entityManager->flush();

        // The admin runs on the platform host: build the link on the client's own domain
        $locale = $tenant->getDefaultLocale();
        $link = 'https://'.$domain->getHostname().$this->urlGenerator->generate('site_review_invite', ['_locale' => $locale, 'token' => $token]);
        $message = $this->translator->trans('review_request.message', [
            '%name%' => $tenant->getName(),
            '%days%' => Project::REVIEW_LINK_DAYS,
            '%url%' => $link,
        ], 'messages', $locale);

        if ($phone = $project->getCustomerPhoneDigits()) {
            return new ReviewRequestResult(ReviewRequestResult::WHATSAPP, $link, 'https://wa.me/'.$phone.'?text='.rawurlencode($message));
        }

        $email = (new Email())
            ->from(new Address($this->from, $tenant->getName()))
            ->to((string) $project->getCustomerEmail())
            ->subject($this->translator->trans('review_request.subject', ['%name%' => $tenant->getName()], 'messages', $locale))
            ->text($message);
        if ($tenant->getEmail()) {
            $email->replyTo($tenant->getEmail());
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // The link works anyway: the client can pass it on themselves
            $this->logger->error('Could not send review request: {message}', ['message' => $e->getMessage()]);

            return new ReviewRequestResult(ReviewRequestResult::EMAIL_FAILED, $link);
        }

        return new ReviewRequestResult(ReviewRequestResult::EMAILED, $link);
    }
}

<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Charge;
use App\Entity\Tenant;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Billing emails to clients. Every send returns false (and logs) instead of
 * throwing, so a mail problem never breaks a payment update.
 */
final readonly class BillingMailer
{
    private const array REMINDER_SUBJECTS = [
        Charge::REMINDER_FIRST => 'billing.reminder.first.subject',
        Charge::REMINDER_SECOND => 'billing.reminder.second.subject',
        Charge::REMINDER_FINAL => 'billing.reminder.final.subject',
    ];

    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
        #[Autowire(env: 'BILLING_BRAND')]
        private string $brand,
    ) {
    }

    public function sendSetupRequest(Tenant $tenant, string $payUrl): bool
    {
        return $this->send($tenant, 'billing.setup.subject', 'setup', [
            'pay_url' => $payUrl,
        ]);
    }

    /** @param list<Charge> $outstanding */
    public function sendReminder(Tenant $tenant, int $stage, array $outstanding, string $payUrl, \DateTimeImmutable $offlineDate): bool
    {
        return $this->send($tenant, self::REMINDER_SUBJECTS[$stage], 'reminder', [
            'stage' => $stage,
            'charges' => $outstanding,
            'pay_url' => $payUrl,
            'offline_date' => $offlineDate,
        ]);
    }

    /** Yearly plan: sent 2 weeks before the yearly payment is collected */
    public function sendRenewalNotice(Tenant $tenant, \DateTimeImmutable $chargeDate, int $amount, string $currency, string $payUrl): bool
    {
        return $this->send($tenant, 'billing.renewal.subject', 'renewal_notice', [
            'charge_date' => $chargeDate,
            'amount' => $amount,
            'currency' => $currency,
            'pay_url' => $payUrl,
        ]);
    }

    /** @param list<Charge> $outstanding */
    public function sendSuspended(Tenant $tenant, array $outstanding, string $payUrl): bool
    {
        return $this->send($tenant, 'billing.suspended.subject', 'suspended', [
            'charges' => $outstanding,
            'pay_url' => $payUrl,
        ]);
    }

    public function sendRestored(Tenant $tenant): bool
    {
        return $this->send($tenant, 'billing.restored.subject', 'restored');
    }

    /** The client someone signed up with an invitation of $inviter has set up payment */
    public function sendInviteReward(Tenant $inviter, Tenant $invitee): bool
    {
        return $this->send($inviter, 'billing.invite_reward.subject', 'invite_reward', [
            'invitee' => $invitee,
        ]);
    }

    /** $subject is a translation key in the "email" domain; the email goes out in the client's email language */
    private function send(Tenant $tenant, string $subject, string $template, array $context = []): bool
    {
        $to = $tenant->getBillingRecipient();
        if (null === $to) {
            $this->logger->warning('Billing email "{subject}" not sent: {tenant} has no billing email address.', ['subject' => $subject, 'tenant' => $tenant->getSlug()]);

            return false;
        }

        $locale = $tenant->getEmailLocale();
        $subject = $this->translator->trans($subject, ['%brand%' => $this->brand], 'email', $locale);

        $email = (new TemplatedEmail())
            ->from(new Address($this->from, $this->brand))
            ->to($to)
            ->locale($locale)
            ->subject($subject)
            ->htmlTemplate(\sprintf('email/billing/%s.html.twig', $template))
            ->context([...$context, 'tenant' => $tenant]);

        try {
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Could not send billing email to {tenant}: {message}', ['tenant' => $tenant->getSlug(), 'message' => $e->getMessage()]);

            return false;
        }
    }
}

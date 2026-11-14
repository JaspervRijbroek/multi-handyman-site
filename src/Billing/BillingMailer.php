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

/**
 * Billing emails to clients. Every send returns false (and logs) instead of
 * throwing, so a mail problem never breaks a payment update.
 */
final readonly class BillingMailer
{
    private const array REMINDER_SUBJECTS = [
        Charge::REMINDER_FIRST => 'Your payment for %s did not come through',
        Charge::REMINDER_SECOND => 'Reminder: your payment for %s is still open',
        Charge::REMINDER_FINAL => 'Final reminder: pay within a week to keep your website online',
    ];

    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
        #[Autowire(env: 'BILLING_BRAND')]
        private string $brand,
    ) {
    }

    public function sendSetupRequest(Tenant $tenant, string $payUrl): bool
    {
        return $this->send($tenant, 'Set up payment for your website', 'setup', [
            'pay_url' => $payUrl,
        ]);
    }

    /** @param list<Charge> $outstanding */
    public function sendReminder(Tenant $tenant, int $stage, array $outstanding, string $payUrl, \DateTimeImmutable $offlineDate): bool
    {
        $periods = implode(', ', array_map(static fn (Charge $c) => $c->getPeriodLabel(), $outstanding));

        return $this->send($tenant, \sprintf(self::REMINDER_SUBJECTS[$stage], $periods), 'reminder', [
            'stage' => $stage,
            'charges' => $outstanding,
            'pay_url' => $payUrl,
            'offline_date' => $offlineDate,
        ]);
    }

    /** Yearly plan: sent 2 weeks before the yearly payment is collected */
    public function sendRenewalNotice(Tenant $tenant, \DateTimeImmutable $chargeDate, int $amount, string $currency, string $payUrl): bool
    {
        return $this->send($tenant, \sprintf('Your yearly payment for %s is coming up on %s', $this->brand, $chargeDate->format('j F Y')), 'renewal_notice', [
            'charge_date' => $chargeDate,
            'amount' => $amount,
            'currency' => $currency,
            'pay_url' => $payUrl,
        ]);
    }

    /** @param list<Charge> $outstanding */
    public function sendSuspended(Tenant $tenant, array $outstanding, string $payUrl): bool
    {
        return $this->send($tenant, 'Your website has been taken offline', 'suspended', [
            'charges' => $outstanding,
            'pay_url' => $payUrl,
        ]);
    }

    public function sendRestored(Tenant $tenant): bool
    {
        return $this->send($tenant, 'Thank you, your website is back online', 'restored');
    }

    private function send(Tenant $tenant, string $subject, string $template, array $context = []): bool
    {
        $to = $tenant->getBillingRecipient();
        if (null === $to) {
            $this->logger->warning('Billing email "{subject}" not sent: {tenant} has no billing email address.', ['subject' => $subject, 'tenant' => $tenant->getSlug()]);

            return false;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->from, $this->brand))
            ->to($to)
            ->subject($subject)
            ->textTemplate(\sprintf('email/billing/%s.txt.twig', $template))
            ->context([...$context, 'tenant' => $tenant, 'brand' => $this->brand]);

        try {
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Could not send billing email to {tenant}: {message}', ['tenant' => $tenant->getSlug(), 'message' => $e->getMessage()]);

            return false;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Charge;
use App\Entity\Tenant;
use App\Repository\ChargeRepository;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Billing of clients through Mollie.
 *
 * 1. Onboarding: the platform is free until the 1st of the next month
 *    (Tenant::$billingStartsOn). The client authorises automatic payments once:
 *    a €0.00 card verification or a €0.01 bank payment (gives a SEPA mandate).
 * 2. On the 1st of every month the client is charged automatically.
 * 3. If that payment does not come through, a reminder with a payment link is sent
 *    right away, a second one after 2 weeks, and a final one after a month
 *    (payment required within a week). A week after the final reminder the site
 *    is taken offline. Paying through the link brings it back immediately.
 *
 * Mollie reports payment status changes to the webhook; the daily
 * app:billing:run command also checks open payments in case a webhook was missed.
 * Charges and payment checks go through the billing queue (BillingMessageHandler),
 * so clients are handled one at a time and a Mollie hiccup is retried.
 */
final readonly class BillingService
{
    public const string METHOD_CARD = 'card';
    public const string METHOD_BANK = 'bank';

    private const string PURPOSE_MANDATE = 'mandate';
    private const string PURPOSE_MONTHLY = 'monthly';
    private const string PURPOSE_OUTSTANDING = 'outstanding';

    /** Card authorisations can be €0.00; a bank payment needs a real (tiny) amount */
    private const array SETUP_AMOUNTS = [self::METHOD_CARD => 0, self::METHOD_BANK => 1];

    public const string SECOND_REMINDER_AFTER = '+14 days';
    public const string FINAL_REMINDER_AFTER = '+1 month';
    public const string OFFLINE_AFTER_FINAL_REMINDER = '+7 days';

    public function __construct(
        private MollieClient $mollie,
        private BillingMailer $mailer,
        private EntityManagerInterface $entityManager,
        private ChargeRepository $charges,
        private TenantRepository $tenants,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
        #[Autowire(env: 'int:BILLING_MONTHLY_PRICE')]
        private int $defaultMonthlyPrice,
        #[Autowire(env: 'BILLING_VAT_RATE')]
        private string $vatRate,
        #[Autowire(env: 'BILLING_CURRENCY')]
        private string $currency,
        #[Autowire(env: 'BILLING_BRAND')]
        private string $brand,
        #[Autowire(env: 'bool:BILLING_WEBHOOKS')]
        private bool $webhooksEnabled,
    ) {
    }

    // Setting up automatic payments

    /** Returns the Mollie checkout URL where the client authorises automatic payments. */
    public function startMandateSetup(Tenant $tenant, string $method): string
    {
        $amount = self::SETUP_AMOUNTS[$method] ?? throw new \InvalidArgumentException('Unknown method '.$method);

        $payment = $this->mollie->createPayment(array_filter([
            'amount' => MollieClient::amount($amount, $this->currency),
            'description' => $this->brand.' – authorisation for automatic payments',
            'sequenceType' => 'first',
            'customerId' => $this->ensureCustomer($tenant),
            'method' => self::METHOD_CARD === $method ? 'creditcard' : null,
            'redirectUrl' => $this->url('billing_return', ['token' => $tenant->getBillingToken()]),
            'webhookUrl' => $this->webhookUrl(),
            'metadata' => ['purpose' => self::PURPOSE_MANDATE, 'tenant' => $tenant->getId()],
        ]));

        return $this->checkoutUrl($tenant, $payment);
    }

    /**
     * Returns the Mollie checkout URL for everything the client still owes.
     * Paid with a method that supports it, this also renews the automatic payments.
     */
    public function startOutstandingPayment(Tenant $tenant): string
    {
        $outstanding = $this->charges->findOutstanding($tenant);
        if (!$outstanding) {
            throw new \LogicException('Nothing to pay.');
        }

        $total = array_sum(array_map(static fn (Charge $c) => $c->getTotalAmount(), $outstanding));
        $periods = implode(', ', array_map(static fn (Charge $c) => $c->getPeriodLabel(), $outstanding));

        $payment = $this->mollie->createPayment(array_filter([
            'amount' => MollieClient::amount($total, $this->currency),
            'description' => $this->brand.' – '.$periods,
            // No working mandate: let this payment set up a new one
            'sequenceType' => $tenant->hasMandate() ? 'oneoff' : 'first',
            'customerId' => $this->ensureCustomer($tenant),
            'redirectUrl' => $this->url('billing_return', ['token' => $tenant->getBillingToken()]),
            'webhookUrl' => $this->webhookUrl(),
            'metadata' => [
                'purpose' => self::PURPOSE_OUTSTANDING,
                'tenant' => $tenant->getId(),
                'charges' => array_map(static fn (Charge $c) => $c->getId(), $outstanding),
            ],
        ]));

        return $this->checkoutUrl($tenant, $payment);
    }

    public function sendSetupEmail(Tenant $tenant): bool
    {
        $sent = $this->mailer->sendSetupRequest($tenant, $this->payUrl($tenant));
        $this->entityManager->flush(); // the billing token may have just been generated

        return $sent;
    }

    // Payment status updates (webhook, return page, daily check)

    /** Checks the payment in the billing queue, e.g. for the webhook */
    public function queuePaymentSync(string $paymentId): void
    {
        $this->bus->dispatch(new SyncPayment($paymentId));
    }

    public function syncPayment(string $paymentId): void
    {
        $this->applyPayment($this->mollie->getPayment($paymentId));
    }

    /** @param array<string, mixed> $payment as returned by the Mollie API */
    private function applyPayment(array $payment): void
    {
        $metadata = $payment['metadata'] ?? [];
        $tenant = isset($metadata['tenant']) ? $this->tenants->find((int) $metadata['tenant']) : null;
        if (null === $tenant) {
            $this->logger->warning('Mollie payment {id} is not linked to a client.', ['id' => $payment['id'] ?? '?']);

            return;
        }

        match ($metadata['purpose'] ?? null) {
            self::PURPOSE_MANDATE => $this->applyMandatePayment($tenant, $payment),
            self::PURPOSE_MONTHLY => $this->applyMonthlyPayment($tenant, $payment),
            self::PURPOSE_OUTSTANDING => $this->applyOutstandingPayment($tenant, $payment),
            default => $this->logger->warning('Mollie payment {id} has an unknown purpose.', ['id' => $payment['id'] ?? '?']),
        };

        $this->entityManager->flush();
    }

    private function applyMandatePayment(Tenant $tenant, array $payment): void
    {
        if ('paid' === $payment['status']) {
            $this->storeMandate($tenant, $payment['mandateId'] ?? null);
        }
    }

    private function applyMonthlyPayment(Tenant $tenant, array $payment): void
    {
        $charge = $this->charges->find((int) ($payment['metadata']['charge'] ?? 0));

        // Only the payment currently collecting the charge may change it
        if (null === $charge || $charge->getTenant() !== $tenant || $charge->getMolliePaymentId() !== $payment['id']) {
            return;
        }

        $now = new \DateTimeImmutable();
        $chargedBack = (float) ($payment['amountChargedBack']['value'] ?? 0) > 0;

        if ('paid' === $payment['status'] && !$chargedBack) {
            if (!$charge->isPaid() && !$charge->isFailed()) {
                $charge->markPaid($payment['id'], $now);
            }

            return;
        }

        $failed = \in_array($payment['status'], ['failed', 'canceled', 'expired'], true);
        if (($failed && !$charge->isFailed() && !$charge->isPaid()) || ($chargedBack && $charge->isPaid())) {
            $charge->markFailed($chargedBack ? 'The payment was reversed by your bank' : $this->failureReason($payment), $now);
            $this->checkMandateStillValid($tenant);
            $this->entityManager->flush();
            $this->sendReminder($tenant, Charge::REMINDER_FIRST, $now);
        }
    }

    private function applyOutstandingPayment(Tenant $tenant, array $payment): void
    {
        if ('paid' !== $payment['status']) {
            return;
        }

        $now = new \DateTimeImmutable();
        foreach ($payment['metadata']['charges'] ?? [] as $chargeId) {
            $charge = $this->charges->find((int) $chargeId);
            if ($charge && $charge->getTenant() === $tenant && $charge->isFailed()) {
                $charge->markPaid($payment['id'], $now);
            }
        }

        if (!empty($payment['mandateId'])) {
            $this->storeMandate($tenant, $payment['mandateId']);
        }

        $this->entityManager->flush();
        $this->restoreIfPaidUp($tenant);
    }

    /** For payments received outside Mollie, e.g. a bank transfer. */
    public function markPaidManually(Charge $charge): void
    {
        $charge->markPaid('manual', new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->restoreIfPaidUp($charge->getTenant());
    }

    /** Brings a suspended site back online once nothing is outstanding any more. */
    private function restoreIfPaidUp(Tenant $tenant): void
    {
        if ($tenant->isSuspended() && !$this->charges->findOutstanding($tenant)) {
            $tenant->unsuspend();
            $this->entityManager->flush();
            $this->mailer->sendRestored($tenant);
        }
    }

    /**
     * Daily safety net in case Mollie could not reach the webhook.
     *
     * @return int the number of payment checks queued
     */
    public function syncPendingCharges(): int
    {
        $queued = 0;
        foreach ($this->charges->findAllPending() as $charge) {
            if (null !== $charge->getMolliePaymentId()) {
                $this->queuePaymentSync($charge->getMolliePaymentId());
                ++$queued;
            }
        }

        return $queued;
    }

    // Monthly charges

    /**
     * Queues a charge for every client that is due for the month of $today. Safe to
     * run every day: a client is charged at most once per month, from the 1st on.
     *
     * @return list<Tenant> the clients queued
     */
    public function chargeDueClients(\DateTimeImmutable $today): array
    {
        $queued = [];
        foreach ($this->tenants->findBy(['active' => true, 'billingExempt' => false]) as $tenant) {
            if ($this->isDue($tenant, $today)) {
                $this->bus->dispatch(new ChargeClient($tenant->getId(), $today->format('Y-m-d')));
                $queued[] = $tenant;
            }
        }

        return $queued;
    }

    /** Charges the client if due; called from the billing queue. Checks again, so a repeated message is harmless. */
    public function chargeIfDue(Tenant $tenant, \DateTimeImmutable $today): ?Charge
    {
        $today = $today->setTime(0, 0);

        if (!$this->isDue($tenant, $today)) {
            return null;
        }

        $charge = new Charge($tenant, $today, $tenant->getMonthlyPrice() ?? $this->defaultMonthlyPrice, $this->vatRate, $this->currency);
        $this->entityManager->persist($charge);
        $this->entityManager->flush(); // the unique (tenant, period) key stops double charges

        $now = new \DateTimeImmutable();

        if (0 === $charge->getTotalAmount()) {
            $charge->markPaid('free', $now);
        } elseif (!$tenant->hasMandate()) {
            $this->failAndRemind($tenant, $charge, 'No payment method has been set up', $now);
        } else {
            try {
                $payment = $this->mollie->createPayment(array_filter([
                    'amount' => MollieClient::amount($charge->getTotalAmount(), $charge->getCurrency()),
                    'description' => $this->brand.' – '.$charge->getPeriodLabel(),
                    'sequenceType' => 'recurring',
                    'customerId' => $tenant->getMollieCustomerId(),
                    'mandateId' => $tenant->getMollieMandateId(),
                    'webhookUrl' => $this->webhookUrl(),
                    'metadata' => ['purpose' => self::PURPOSE_MONTHLY, 'tenant' => $tenant->getId(), 'charge' => $charge->getId()],
                ]));
                $charge->startCollecting($payment['id']);
                $this->entityManager->flush();

                // Card payments are often decided straight away
                $this->applyPayment($payment);
            } catch (MollieException $e) {
                // E.g. the mandate was revoked or the card expired
                $this->logger->warning('Automatic payment for {tenant} failed: {message}', ['tenant' => $tenant->getSlug(), 'message' => $e->getMessage()]);
                $this->checkMandateStillValid($tenant);
                $this->failAndRemind($tenant, $charge, 'The automatic payment could not be started', $now);
            }
        }

        $this->entityManager->flush();

        return $charge;
    }

    private function isDue(Tenant $tenant, \DateTimeImmutable $today): bool
    {
        $today = $today->setTime(0, 0);

        return !$tenant->isBillingExempt() && !$tenant->isSuspended() && $tenant->isActive()
            && $today >= $tenant->getBillingStartsOn()
            && null === $this->charges->findForPeriod($tenant, $today);
    }

    // Reminders and taking sites offline

    /**
     * Sends the reminders that are due and takes sites offline, per client,
     * based on their oldest unpaid charge:
     *   failed               → 1st reminder (normally sent right when the payment fails)
     *   + 2 weeks            → 2nd reminder
     *   + 1 month            → final reminder: pay within a week
     *   final reminder + 1 week → site offline
     *
     * @return array{reminders: int, suspended: list<Tenant>}
     */
    public function processOverdue(\DateTimeImmutable $now): array
    {
        $result = ['reminders' => 0, 'suspended' => []];
        $handled = [];

        foreach ($this->charges->findAllOutstanding() as $oldest) {
            $tenant = $oldest->getTenant();
            if (isset($handled[$tenant->getId()]) || $tenant->isBillingExempt()) {
                continue;
            }
            $handled[$tenant->getId()] = true;

            $failedAt = $oldest->getFailedAt() ?? $oldest->getCreatedAt();
            $stage = $oldest->getReminderStage();

            $due = match (true) {
                $stage < Charge::REMINDER_FIRST => Charge::REMINDER_FIRST,
                Charge::REMINDER_FIRST === $stage && $now >= $failedAt->modify(self::SECOND_REMINDER_AFTER) => Charge::REMINDER_SECOND,
                Charge::REMINDER_SECOND === $stage && $now >= $failedAt->modify(self::FINAL_REMINDER_AFTER) => Charge::REMINDER_FINAL,
                default => null,
            };

            if (null !== $due) {
                if ($this->sendReminder($tenant, $due, $now)) {
                    ++$result['reminders'];
                }
                continue;
            }

            // Always a full week after the final reminder was actually sent
            $finalSentAt = $oldest->getLastReminderAt();
            if (Charge::REMINDER_FINAL === $stage && !$tenant->isSuspended()
                && null !== $finalSentAt && $now >= self::offlineDate($finalSentAt)) {
                $tenant->suspend($now);
                $this->entityManager->flush();
                $this->mailer->sendSuspended($tenant, $this->charges->findOutstanding($tenant), $this->payUrl($tenant));
                $result['suspended'][] = $tenant;
            }
        }

        $this->entityManager->flush();

        return $result;
    }

    /** The deadline mentioned in the final reminder */
    public static function offlineDate(\DateTimeImmutable $finalReminderSentAt): \DateTimeImmutable
    {
        return $finalReminderSentAt->modify(self::OFFLINE_AFTER_FINAL_REMINDER);
    }

    private function failAndRemind(Tenant $tenant, Charge $charge, string $reason, \DateTimeImmutable $now): void
    {
        $charge->markFailed($reason, $now);
        $this->entityManager->flush();
        $this->sendReminder($tenant, Charge::REMINDER_FIRST, $now);
    }

    /**
     * Emails the reminder for $stage, listing everything that is outstanding.
     * The stage is only recorded when the email went out, so a failed send is retried the next day.
     */
    private function sendReminder(Tenant $tenant, int $stage, \DateTimeImmutable $now): bool
    {
        $outstanding = $this->charges->findOutstanding($tenant);
        if (!$outstanding) {
            return false;
        }

        $sent = $this->mailer->sendReminder($tenant, $stage, $outstanding, $this->payUrl($tenant), self::offlineDate($now));

        // Without a billing address nobody can be reminded; keep the schedule going anyway
        if ($sent || null === $tenant->getBillingRecipient()) {
            // The schedule follows the oldest charge; newer ones just ride along in the emails
            $outstanding[0]->reminderSent(max($stage, $outstanding[0]->getReminderStage()), $now);
            foreach (\array_slice($outstanding, 1) as $charge) {
                if (0 === $charge->getReminderStage()) {
                    $charge->reminderSent(Charge::REMINDER_FIRST, $now);
                }
            }
            $this->entityManager->flush();
        }

        return $sent;
    }

    // Helpers

    /** The payment link from the emails: works without logging in */
    public function payUrl(Tenant $tenant): string
    {
        return $this->url('billing_pay', ['token' => $tenant->getBillingToken()]);
    }

    private function ensureCustomer(Tenant $tenant): string
    {
        if (null === $tenant->getMollieCustomerId()) {
            $customer = $this->mollie->createCustomer($tenant->getLegalName() ?: $tenant->getName(), $tenant->getBillingRecipient(), [
                'tenant' => $tenant->getId(),
                'slug' => $tenant->getSlug(),
            ]);
            $tenant->setMollieCustomerId($customer['id']);
            $this->entityManager->flush();
        }

        return $tenant->getMollieCustomerId();
    }

    private function storeMandate(Tenant $tenant, ?string $mandateId): void
    {
        $customerId = $tenant->getMollieCustomerId();
        if (null === $customerId) {
            return;
        }

        $mandates = $mandateId ? [$this->mollie->getMandate($customerId, $mandateId)] : $this->mollie->listMandates($customerId);
        foreach ($mandates as $mandate) {
            if (\in_array($mandate['status'] ?? null, ['valid', 'pending'], true)) {
                $tenant->setMandate($mandate['id'], self::describeMandate($mandate));

                return;
            }
        }
    }

    private function checkMandateStillValid(Tenant $tenant): void
    {
        if (!$tenant->hasMandate() || null === $tenant->getMollieCustomerId()) {
            return;
        }

        try {
            $mandate = $this->mollie->getMandate($tenant->getMollieCustomerId(), $tenant->getMollieMandateId());
            if ('invalid' === ($mandate['status'] ?? null)) {
                $tenant->setMandate(null, null);
            }
        } catch (MollieException) {
            // Revoked mandates are no longer found
            $tenant->setMandate(null, null);
        }
    }

    /** e.g. "Visa •••• 4242" or "Direct debit NL55 •••• 0123" */
    public static function describeMandate(array $mandate): string
    {
        $details = $mandate['details'] ?? [];

        if ('creditcard' === ($mandate['method'] ?? null)) {
            return trim(($details['cardLabel'] ?? 'Card').' •••• '.substr((string) ($details['cardNumber'] ?? ''), -4));
        }

        if (!empty($details['consumerAccount'])) {
            $iban = str_replace(' ', '', $details['consumerAccount']);

            return 'Direct debit '.substr($iban, 0, 4).' •••• '.substr($iban, -4);
        }

        return ucfirst((string) ($mandate['method'] ?? 'Automatic payment'));
    }

    private function failureReason(array $payment): string
    {
        return match ($payment['status']) {
            'expired' => 'The payment expired',
            'canceled' => 'The payment was cancelled',
            default => $payment['details']['bankReason'] ?? match ($payment['details']['failureReason'] ?? null) {
                'insufficient_funds' => 'Insufficient funds',
                'card_expired' => 'The card has expired',
                'card_declined', 'authentication_failed', 'authentication_rejected' => 'The card was declined',
                default => 'The payment was declined',
            },
        };
    }

    private function checkoutUrl(Tenant $tenant, array $payment): string
    {
        $tenant->setLastCheckoutPaymentId($payment['id']);
        $this->entityManager->flush();

        return $payment['_links']['checkout']['href'] ?? throw new MollieException('Mollie returned no checkout link.');
    }

    private function webhookUrl(): ?string
    {
        return $this->webhooksEnabled ? $this->url('billing_webhook') : null;
    }

    private function url(string $route, array $parameters = []): string
    {
        return $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}

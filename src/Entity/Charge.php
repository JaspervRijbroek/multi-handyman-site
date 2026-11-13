<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChargeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a client owes for one month (monthly plan) or twelve months (yearly plan).
 *
 * pending → the automatic (recurring) payment is on its way
 * paid    → done
 * failed  → the automatic payment did not come through: reminders with a payment
 *           link go out (now, after 2 weeks, after a month) and a week after the
 *           last reminder the site is taken offline.
 */
#[ORM\Entity(repositoryClass: ChargeRepository::class)]
#[ORM\UniqueConstraint(columns: ['tenant_id', 'period'])]
#[ORM\Index(columns: ['status'])]
class Charge implements TenantOwnedInterface
{
    use TenantOwnedTrait;

    public const string PENDING = 'pending';
    public const string PAID = 'paid';
    public const string FAILED = 'failed';

    /** Reminder stages, see App\Billing\BillingService::sendDueReminders() */
    public const int REMINDER_FIRST = 1;
    public const int REMINDER_SECOND = 2;
    public const int REMINDER_FINAL = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** First day of the (first) month this charge is for */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $period;

    /** How many months it covers: 1, or 12 for the yearly plan */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $months = 1;

    /** Amounts in cents */
    #[ORM\Column]
    private int $netAmount = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $vatRate = '0.00';

    #[ORM\Column]
    private int $totalAmount = 0;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(length: 10)]
    private string $status = self::PENDING;

    /** The automatic payment, or the payment through the payment link that settled this charge */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $molliePaymentId = null;

    /** When the automatic payment failed: the reminder schedule counts from here */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $reminderStage = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastReminderAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Tenant $tenant, \DateTimeImmutable $period, int $netAmount, string $vatRate, string $currency, int $months = 1)
    {
        $this->tenant = $tenant;
        $this->period = $period->modify('first day of this month')->setTime(0, 0);
        $this->months = $months;
        $this->netAmount = $netAmount;
        $this->vatRate = $vatRate;
        $this->totalAmount = self::totalWithVat($netAmount, $vatRate);
        $this->currency = $currency;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->getPeriodLabel();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPeriod(): \DateTimeImmutable
    {
        return $this->period;
    }

    public function getMonths(): int
    {
        return $this->months;
    }

    /** The day after the last day this charge covers, e.g. 2027-12-01 for a yearly charge from 2026-12-01 */
    public function getPaidUntil(): \DateTimeImmutable
    {
        return $this->period->modify(\sprintf('+%d months', $this->months));
    }

    public function covers(\DateTimeImmutable $day): bool
    {
        return $day >= $this->period && $day < $this->getPaidUntil();
    }

    /** e.g. "December 2026", or "December 2026 – November 2027" for a year */
    public function getPeriodLabel(): string
    {
        if (1 === $this->months) {
            return $this->period->format('F Y');
        }

        return $this->period->format('F Y').' – '.$this->getPaidUntil()->modify('-1 month')->format('F Y');
    }

    public static function totalWithVat(int $netAmount, string $vatRate): int
    {
        return (int) round($netAmount * (1 + (float) $vatRate / 100));
    }

    public function getNetAmount(): int
    {
        return $this->netAmount;
    }

    public function getVatRate(): string
    {
        return $this->vatRate;
    }

    public function getVatAmount(): int
    {
        return $this->totalAmount - $this->netAmount;
    }

    public function getTotalAmount(): int
    {
        return $this->totalAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPaid(): bool
    {
        return self::PAID === $this->status;
    }

    public function isFailed(): bool
    {
        return self::FAILED === $this->status;
    }

    public function getMolliePaymentId(): ?string
    {
        return $this->molliePaymentId;
    }

    public function startCollecting(string $molliePaymentId): void
    {
        $this->status = self::PENDING;
        $this->molliePaymentId = $molliePaymentId;
    }

    public function markPaid(string $molliePaymentId, \DateTimeImmutable $now): void
    {
        $this->status = self::PAID;
        $this->molliePaymentId = $molliePaymentId;
        $this->paidAt = $now;
    }

    public function markFailed(string $reason, \DateTimeImmutable $now): void
    {
        $this->status = self::FAILED;
        $this->failureReason = mb_substr($reason, 0, 255);
        $this->failedAt = $now;
        $this->reminderStage = 0;
        $this->lastReminderAt = null;
        $this->paidAt = null;
    }

    public function getFailedAt(): ?\DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getReminderStage(): int
    {
        return $this->reminderStage;
    }

    public function getLastReminderAt(): ?\DateTimeImmutable
    {
        return $this->lastReminderAt;
    }

    public function reminderSent(int $stage, \DateTimeImmutable $now): void
    {
        $this->reminderStage = $stage;
        $this->lastReminderAt = $now;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

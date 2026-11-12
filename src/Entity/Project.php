<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A finished job with a photo: the thing clients will actually update.
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
class Project implements TenantOwnedInterface
{
    use TenantOwnedTrait;

    /** How long the review link sent to the customer works */
    public const REVIEW_LINK_DAYS = 7;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $title = [];

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $description = [];

    /** File name inside public/uploads/projects/ */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    private bool $published = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** The customer's email address or phone number, to ask them for a review (optional) */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Length(max: 180)]
    private ?string $customerContact = null;

    /** Secret part of the review link; cleared once the customer has left their review */
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $reviewToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewRequestedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return (string) (array_values($this->title)[0] ?? 'Project');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return array<string, string> */
    public function getTitle(): array
    {
        return $this->title;
    }

    public function setTitle(?array $title): static
    {
        $this->title = TranslatableText::clean($title);

        return $this;
    }

    /** @return array<string, string> */
    public function getDescription(): array
    {
        return $this->description;
    }

    public function setDescription(?array $description): static
    {
        $this->description = TranslatableText::clean($description);

        return $this;
    }

    public function getImageName(): ?string
    {
        return $this->imageName;
    }

    public function setImageName(?string $imageName): static
    {
        $this->imageName = $imageName;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): static
    {
        $this->published = $published;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCustomerContact(): ?string
    {
        return $this->customerContact;
    }

    public function setCustomerContact(?string $customerContact): static
    {
        $this->customerContact = trim((string) $customerContact) ?: null;

        return $this;
    }

    public function getCustomerEmail(): ?string
    {
        return filter_var($this->customerContact, \FILTER_VALIDATE_EMAIL) ?: null;
    }

    /** Digits only, as required by https://wa.me/<number> */
    public function getCustomerPhoneDigits(): ?string
    {
        if (!$this->customerContact || $this->getCustomerEmail()) {
            return null;
        }

        return preg_replace('/\D/', '', $this->customerContact) ?: null;
    }

    #[Assert\Callback]
    public function validateCustomerContact(ExecutionContextInterface $context): void
    {
        if ($this->customerContact && !$this->getCustomerEmail() && \strlen((string) $this->getCustomerPhoneDigits()) < 9) {
            $context->buildViolation('Enter an email address or a phone number with country code, e.g. +34 600 111 222.')
                ->atPath('customerContact')
                ->addViolation();
        }
    }

    public function getReviewToken(): ?string
    {
        return $this->reviewToken;
    }

    public function getReviewRequestedAt(): ?\DateTimeImmutable
    {
        return $this->reviewRequestedAt;
    }

    /** Creates a new review link (an older one stops working) and returns its token. */
    public function startReviewRequest(): string
    {
        $this->reviewToken = bin2hex(random_bytes(16));
        $this->reviewRequestedAt = new \DateTimeImmutable();

        return $this->reviewToken;
    }

    public function isReviewLinkValid(): bool
    {
        return null !== $this->reviewToken
            && $this->reviewRequestedAt > new \DateTimeImmutable(\sprintf('-%d days', self::REVIEW_LINK_DAYS));
    }

    /** The customer left their review: the link stops working and their contact details are no longer needed. */
    public function completeReviewRequest(): static
    {
        $this->reviewToken = null;
        $this->customerContact = null;

        return $this;
    }
}

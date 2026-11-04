<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EnquiryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A message sent through a client site's contact form.
 */
#[ORM\Entity(repositoryClass: EnquiryRepository::class)]
#[ORM\Index(columns: ['created_at'])]
class Enquiry implements TenantOwnedInterface
{
    use TenantOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    /** Email address or phone number, whatever the visitor prefers */
    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $contact = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 5000)]
    private string $message = '';

    #[ORM\Column(length: 5)]
    private string $locale = 'en';

    #[ORM\Column]
    private bool $handled = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getContact(): string
    {
        return $this->contact;
    }

    public function setContact(?string $contact): static
    {
        $this->contact = trim((string) $contact);

        return $this;
    }

    public function getContactEmail(): ?string
    {
        return filter_var($this->contact, \FILTER_VALIDATE_EMAIL) ?: null;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = trim((string) $message);

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function isHandled(): bool
    {
        return $this->handled;
    }

    public function setHandled(bool $handled): static
    {
        $this->handled = $handled;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

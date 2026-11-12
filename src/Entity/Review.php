<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A customer review, written on the client's website. Only shown after the
 * client publishes it in the admin.
 *
 * Reviews written through a review link (sent when a project is added) are
 * linked to that project, and may be anonymous (empty author name).
 */
#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[ORM\Index(columns: ['published', 'created_at'])]
class Review implements TenantOwnedInterface
{
    use TenantOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Empty for an anonymous review; the public form requires a name (see ReviewType) */
    #[ORM\Column(length: 80)]
    #[Assert\Length(max: 80)]
    private string $authorName = '';

    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $town = null;

    /** 1 to 5 stars */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 1, max: 5)]
    private int $rating = 5;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 2000)]
    private string $text = '';

    #[ORM\Column(length: 5)]
    private string $locale = 'en';

    #[ORM\Column]
    private bool $published = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** The job this review is about, when written through a review link */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Project $project = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->authorName ?: 'Anonymous';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function setAuthorName(?string $authorName): static
    {
        $this->authorName = trim((string) $authorName);

        return $this;
    }

    public function getTown(): ?string
    {
        return $this->town;
    }

    public function setTown(?string $town): static
    {
        $this->town = trim((string) $town) ?: null;

        return $this;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function setRating(?int $rating): static
    {
        $this->rating = (int) $rating;

        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(?string $text): static
    {
        $this->text = trim((string) $text);

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

    public function isAnonymous(): bool
    {
        return '' === $this->authorName;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A finished job with a photo: the thing clients will actually update.
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
class Project implements TenantOwnedInterface
{
    use TenantOwnedTrait;

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
}

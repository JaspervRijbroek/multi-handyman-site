<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ServiceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A service the business offers, e.g. "Plumbing" or "Painting".
 */
#[ORM\Entity(repositoryClass: ServiceRepository::class)]
class Service implements TenantOwnedInterface
{
    use TenantOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\Count(min: 1, minMessage: 'Enter a title in at least one language.')]
    private array $title = [];

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $description = [];

    #[ORM\Column]
    private int $position = 0;

    public function __toString(): string
    {
        return (string) (array_values($this->title)[0] ?? 'Service');
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position ?? 0;

        return $this;
    }
}

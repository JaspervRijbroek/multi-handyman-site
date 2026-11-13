<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DomainRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A hostname that serves a tenant's website. A tenant can have several
 * (example.com, www.example.com); non-primary ones redirect to the primary one.
 */
#[ORM\Entity(repositoryClass: DomainRepository::class)]
#[UniqueEntity('hostname')]
class Domain
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 253, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Hostname]
    private string $hostname = '';

    #[ORM\ManyToOne(inversedBy: 'domains')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Tenant $tenant = null;

    #[ORM\Column]
    private bool $isPrimary = false;

    /** Turns what people type ("https://www.Example.com/") into "example.com". */
    public static function clean(?string $hostname): string
    {
        $hostname = strtolower(trim((string) $hostname));
        $hostname = (string) preg_replace('#^[a-z]+://#', '', $hostname);
        $hostname = explode('/', $hostname, 2)[0];
        $hostname = explode(':', $hostname, 2)[0];

        return (string) preg_replace('/^www\./', '', rtrim($hostname, '.'));
    }

    public function __toString(): string
    {
        return $this->hostname;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function setHostname(?string $hostname): static
    {
        $this->hostname = strtolower(trim((string) $hostname));

        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): static
    {
        $this->isPrimary = $isPrimary;

        return $this;
    }
}

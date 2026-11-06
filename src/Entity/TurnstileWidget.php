<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TurnstileWidgetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Cloudflare Turnstile widget, created and kept up to date by
 * App\Turnstile\TurnstileWidgetSync. A widget only works on the hostnames it lists
 * (and their subdomains). A widget can only hold a limited number of hostnames,
 * so client domains are spread over as many widgets as needed.
 */
#[ORM\Entity(repositoryClass: TurnstileWidgetRepository::class)]
class TurnstileWidget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $siteKey;

    #[ORM\Column(length: 255)]
    private string $secretKey;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $hostnames = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    public function __construct(string $siteKey, string $secretKey)
    {
        $this->siteKey = $siteKey;
        $this->secretKey = $secretKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSiteKey(): string
    {
        return $this->siteKey;
    }

    public function getSecretKey(): string
    {
        return $this->secretKey;
    }

    /** Cloudflare issued new keys, e.g. because the widget was deleted in the dashboard. */
    public function replaceKeys(string $siteKey, string $secretKey): static
    {
        $this->siteKey = $siteKey;
        $this->secretKey = $secretKey;

        return $this;
    }

    /** @return list<string> */
    public function getHostnames(): array
    {
        return $this->hostnames;
    }

    /** @param list<string> $hostnames */
    public function setHostnames(array $hostnames): static
    {
        sort($hostnames);
        $this->hostnames = array_values(array_unique($hostnames));

        return $this;
    }

    public function covers(string $hostname): bool
    {
        return \App\Turnstile\Hostnames::isCovered($hostname, $this->hostnames);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function markSynced(): static
    {
        $this->syncedAt = new \DateTimeImmutable();

        return $this;
    }
}

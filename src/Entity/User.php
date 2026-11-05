<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * An admin login. Clients belong to one tenant; super admins (you) have no tenant.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity('email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ROLE_CLIENT = 'ROLE_CLIENT';
    public const string ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    /** sha256 of the one-time "set your password" link token (welcome email) */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $passwordSetupTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordSetupExpiresAt = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    public function __toString(): string
    {
        return $this->email;
    }

    #[Assert\Callback]
    public function validateTenant(ExecutionContextInterface $context): void
    {
        if (null === $this->tenant && !$this->isSuperAdmin()) {
            $context->buildViolation('A client login must belong to a client.')
                ->atPath('tenant')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = strtolower(trim((string) $email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, self::ROLE_CLIENT]));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique($roles));

        return $this;
    }

    public function isSuperAdmin(): bool
    {
        return \in_array(self::ROLE_SUPER_ADMIN, $this->roles, true);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

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

    /**
     * Creates a one-time token for a "set your password" link and returns it.
     * Only its hash is stored, so a database leak does not leak working links.
     */
    public function createPasswordSetupToken(string $validFor = '+7 days'): string
    {
        $token = bin2hex(random_bytes(32));
        $this->passwordSetupTokenHash = self::hashToken($token);
        $this->passwordSetupExpiresAt = new \DateTimeImmutable($validFor);

        return $token;
    }

    public function isPasswordSetupTokenExpired(): bool
    {
        return null === $this->passwordSetupExpiresAt || $this->passwordSetupExpiresAt < new \DateTimeImmutable();
    }

    public function clearPasswordSetupToken(): static
    {
        $this->passwordSetupTokenHash = null;
        $this->passwordSetupExpiresAt = null;

        return $this;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }
}

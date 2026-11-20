<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An invitation a client (the tenant) emailed to another business, see App\Invitation\Invitations.
 * The link works once: the sign-up made with it is linked here. The invitee gets their
 * second month free; the inviter gets a free month once the invitee has set up payment.
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
class Invitation implements TenantOwnedInterface
{
    use TenantOwnedTrait;

    /** Every client can send this many, used or not */
    public const int PER_CLIENT = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email;

    /** Secret part of the sign-up link */
    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    /** The sign-up made with this invitation; null while unused */
    #[ORM\OneToOne(mappedBy: 'invitation', targetEntity: Signup::class)]
    private ?Signup $signup = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Tenant $inviter, string $name, string $email)
    {
        $this->tenant = $inviter;
        $this->name = trim($name);
        $this->email = trim($email);
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return 'Invited by '.$this->tenant?->getName();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getSignup(): ?Signup
    {
        return $this->signup;
    }

    public function isUsed(): bool
    {
        return null !== $this->signup;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** For the client's list of invitations */
    public function getStatusLabel(): string
    {
        $invitee = $this->signup?->getTenant();

        return match (true) {
            null === $this->signup => 'Sent',
            $this->signup->isPending() => 'Signed up, waiting for approval',
            null === $invitee => 'Not accepted',
            null !== $invitee->getInviterRewardedAt() => 'Free month earned',
            default => 'Waiting for them to set up payment',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SignupRepository;
use App\Validator\AvailableLoginEmail;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A request for a new client website, filled in by the client on /register
 * (or by a super admin in the admin / console). Nothing is created until a
 * super admin approves it; App\Onboarding\ClientOnboarding then creates the
 * client, its domain(s) and login, and connects Google.
 */
#[ORM\Entity(repositoryClass: SignupRepository::class)]
#[ORM\Index(columns: ['status'])]
class Signup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: SignupStatus::class)]
    private SignupStatus $status = SignupStatus::Pending;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $businessName = '';

    /** Owner / contact person; also used as the default legal name */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $contactName = '';

    /** Becomes the client's login and the address enquiries are sent to */
    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[AvailableLoginEmail]
    private string $email = '';

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 30)]
    #[Assert\Regex('/^\+?[\d\s().-]{6,}$/', message: 'Enter a valid phone number.')]
    private string $phone = '';

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    #[Assert\Regex('/^\+?[\d\s().-]{6,}$/', message: 'Enter a valid phone number.')]
    private ?string $whatsapp = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $streetAddress = '';

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private string $postalCode = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $city = '';

    #[ORM\Column(length: 2)]
    #[Assert\NotBlank]
    #[Assert\Country]
    private string $country = 'ES';

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $serviceArea = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $taxId = null;

    /** Own domain name, if the client has one (without www.) */
    #[ORM\Column(length: 253, nullable: true)]
    #[Assert\Hostname(requireTld: true)]
    private ?string $domain = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\Count(min: 1, minMessage: 'Choose at least one language.')]
    #[Assert\All([new Assert\Choice(choices: Tenant::SUPPORTED_LOCALES)])]
    private array $locales = ['en', 'es'];

    #[ORM\Column(length: 5)]
    #[Assert\Choice(choices: Tenant::SUPPORTED_LOCALES)]
    private string $defaultLocale = 'en';

    #[ORM\Column(length: 10, options: ['default' => Tenant::PLAN_MONTHLY])]
    #[Assert\Choice(choices: Tenant::PLANS)]
    private string $billingPlan = Tenant::PLAN_MONTHLY;

    /** What the business does, anything else we should know */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 3000)]
    private ?string $message = null;

    /** Hash of the password the client chose when signing up (null when added by an admin) */
    #[ORM\Column(nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** The client created from this sign-up, once approved */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $tenant = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->businessName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): SignupStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return SignupStatus::Pending === $this->status;
    }

    public function approve(Tenant $tenant): static
    {
        $this->status = SignupStatus::Approved;
        $this->tenant = $tenant;
        $this->decidedAt = new \DateTimeImmutable();
        // The hash now lives on the login; don't keep a second copy around
        $this->passwordHash = null;

        return $this;
    }

    public function reject(): static
    {
        $this->status = SignupStatus::Rejected;
        $this->decidedAt = new \DateTimeImmutable();
        $this->passwordHash = null;

        return $this;
    }

    public function getBusinessName(): string
    {
        return $this->businessName;
    }

    public function setBusinessName(?string $businessName): static
    {
        $this->businessName = trim((string) $businessName);

        return $this;
    }

    public function getContactName(): string
    {
        return $this->contactName;
    }

    public function setContactName(?string $contactName): static
    {
        $this->contactName = trim((string) $contactName);

        return $this;
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

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = trim((string) $phone);

        return $this;
    }

    public function getWhatsapp(): ?string
    {
        return $this->whatsapp;
    }

    public function setWhatsapp(?string $whatsapp): static
    {
        $this->whatsapp = trim((string) $whatsapp) ?: null;

        return $this;
    }

    public function getStreetAddress(): string
    {
        return $this->streetAddress;
    }

    public function setStreetAddress(?string $streetAddress): static
    {
        $this->streetAddress = trim((string) $streetAddress);

        return $this;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = trim((string) $postalCode);

        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = trim((string) $city);

        return $this;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = strtoupper(trim((string) $country)) ?: 'ES';

        return $this;
    }

    public function getAddressLine(): string
    {
        return Tenant::formatAddress($this->streetAddress, $this->postalCode, $this->city);
    }

    public function getServiceArea(): ?string
    {
        return $this->serviceArea;
    }

    public function setServiceArea(?string $serviceArea): static
    {
        $this->serviceArea = trim((string) $serviceArea) ?: null;

        return $this;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function setTaxId(?string $taxId): static
    {
        $this->taxId = strtoupper(trim((string) $taxId)) ?: null;

        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /** Accepts what people type ("https://www.Example.com/") and keeps "example.com". */
    public function setDomain(?string $domain): static
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('#^[a-z]+://#', '', $domain);
        $domain = explode('/', $domain, 2)[0];
        $domain = preg_replace('/^www\./', '', $domain);

        $this->domain = $domain ?: null;

        return $this;
    }

    /** @return list<string> */
    public function getLocales(): array
    {
        return $this->locales;
    }

    /** @param list<string> $locales */
    public function setLocales(array $locales): static
    {
        $this->locales = array_values(array_unique($locales));

        return $this;
    }

    public function getBillingPlan(): string
    {
        return $this->billingPlan;
    }

    public function setBillingPlan(?string $billingPlan): static
    {
        $this->billingPlan = $billingPlan ?? Tenant::PLAN_MONTHLY;

        return $this;
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function setDefaultLocale(?string $defaultLocale): static
    {
        $this->defaultLocale = $defaultLocale ?? 'en';

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = trim((string) $message) ?: null;

        return $this;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(?string $passwordHash): static
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    public function hasPassword(): bool
    {
        return null !== $this->passwordHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }
}

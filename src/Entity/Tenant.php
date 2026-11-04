<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TenantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A client business with its own website.
 */
#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[UniqueEntity('slug')]
class Tenant
{
    public const array SUPPORTED_LOCALES = ['en', 'es', 'nl', 'de', 'fr'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(length: 80, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9-]+$/', message: 'Use lowercase letters, numbers and dashes only.')]
    private string $slug = '';

    #[ORM\Column]
    private bool $active = true;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\Count(min: 1)]
    #[Assert\All([new Assert\Choice(choices: self::SUPPORTED_LOCALES)])]
    private array $locales = ['en', 'es'];

    #[ORM\Column(length: 5)]
    #[Assert\Choice(choices: self::SUPPORTED_LOCALES)]
    private string $defaultLocale = 'en';

    #[ORM\Column(length: 7)]
    #[Assert\Regex('/^#[0-9a-fA-F]{6}$/')]
    private string $primaryColor = '#d9480f';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $whatsapp = null;

    /** Where enquiries from the contact form are sent */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $serviceArea = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $tagline = [];

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $intro = [];

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $about = [];

    // Aviso legal (LSSI art. 10): who runs the website

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $taxId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legalAddress = null;

    // SEO & analytics

    /** Content of the google-site-verification meta tag (Search Console) */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $googleSiteVerification = null;

    /** Cookieless analytics (Plausible), no consent banner needed */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $plausibleDomain = null;

    /** @var Collection<int, Domain> */
    #[ORM\OneToMany(targetEntity: Domain::class, mappedBy: 'tenant', cascade: ['persist'], orphanRemoval: true)]
    private Collection $domains;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->domains = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name;
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
        $this->name = (string) $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = (string) $slug;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Enabled languages, always with the default language first.
     *
     * @return list<string>
     */
    public function getLocales(): array
    {
        return array_values(array_unique([$this->defaultLocale, ...$this->locales]));
    }

    /**
     * @param list<string> $locales
     */
    public function setLocales(array $locales): static
    {
        $this->locales = array_values(array_unique($locales));

        return $this;
    }

    public function hasLocale(string $locale): bool
    {
        return \in_array($locale, $this->getLocales(), true);
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

    public function getPrimaryColor(): string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(?string $primaryColor): static
    {
        $this->primaryColor = $primaryColor ?? '#d9480f';

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    /** Phone number usable in a tel: link */
    public function getPhoneLink(): ?string
    {
        return $this->phone ? preg_replace('/[^\d+]/', '', $this->phone) : null;
    }

    public function getWhatsapp(): ?string
    {
        return $this->whatsapp;
    }

    public function setWhatsapp(?string $whatsapp): static
    {
        $this->whatsapp = $whatsapp;

        return $this;
    }

    /** Digits only, as required by https://wa.me/<number> */
    public function getWhatsappDigits(): ?string
    {
        $digits = $this->whatsapp ? preg_replace('/\D/', '', $this->whatsapp) : null;

        return $digits ?: null;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getServiceArea(): ?string
    {
        return $this->serviceArea;
    }

    public function setServiceArea(?string $serviceArea): static
    {
        $this->serviceArea = $serviceArea;

        return $this;
    }

    /** @return array<string, string> */
    public function getTagline(): array
    {
        return $this->tagline;
    }

    public function setTagline(?array $tagline): static
    {
        $this->tagline = TranslatableText::clean($tagline);

        return $this;
    }

    /** @return array<string, string> */
    public function getIntro(): array
    {
        return $this->intro;
    }

    public function setIntro(?array $intro): static
    {
        $this->intro = TranslatableText::clean($intro);

        return $this;
    }

    /** @return array<string, string> */
    public function getAbout(): array
    {
        return $this->about;
    }

    public function setAbout(?array $about): static
    {
        $this->about = TranslatableText::clean($about);

        return $this;
    }

    public function getLegalName(): ?string
    {
        return $this->legalName;
    }

    public function setLegalName(?string $legalName): static
    {
        $this->legalName = $legalName;

        return $this;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function setTaxId(?string $taxId): static
    {
        $this->taxId = $taxId;

        return $this;
    }

    public function getLegalAddress(): ?string
    {
        return $this->legalAddress;
    }

    public function setLegalAddress(?string $legalAddress): static
    {
        $this->legalAddress = $legalAddress;

        return $this;
    }

    public function getGoogleSiteVerification(): ?string
    {
        return $this->googleSiteVerification;
    }

    public function setGoogleSiteVerification(?string $googleSiteVerification): static
    {
        $this->googleSiteVerification = $googleSiteVerification;

        return $this;
    }

    public function getPlausibleDomain(): ?string
    {
        return $this->plausibleDomain;
    }

    public function setPlausibleDomain(?string $plausibleDomain): static
    {
        $this->plausibleDomain = $plausibleDomain;

        return $this;
    }

    /** @return Collection<int, Domain> */
    public function getDomains(): Collection
    {
        return $this->domains;
    }

    public function addDomain(Domain $domain): static
    {
        if (!$this->domains->contains($domain)) {
            $this->domains->add($domain);
            $domain->setTenant($this);
        }

        return $this;
    }

    public function removeDomain(Domain $domain): static
    {
        $this->domains->removeElement($domain);

        return $this;
    }

    public function getPrimaryDomain(): ?Domain
    {
        foreach ($this->domains as $domain) {
            if ($domain->isPrimary()) {
                return $domain;
            }
        }

        return $this->domains->first() ?: null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

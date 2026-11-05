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

    // Business address: shown on the website and its map, and used for local SEO

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $streetAddress = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $city = null;

    #[ORM\Column(length: 2)]
    #[Assert\Country]
    private string $country = 'ES';

    /**
     * Show the street address on the website. Off for people working from home:
     * the map and Google then only show the town.
     */
    #[ORM\Column]
    private bool $showStreetAddress = true;

    /** Filled automatically from the address (Google Geocoding) */
    #[ORM\Column(nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $longitude = null;

    /** File name of the uploaded logo in public/uploads/logos */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoName = null;

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

    /** Content of a google-site-verification meta tag you add by hand (optional) */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $googleSiteVerification = null;

    /** Cookieless analytics (Plausible), no consent banner needed */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $plausibleDomain = null;

    // Google, set up automatically by App\Onboarding\ClientOnboarding

    /** google-site-verification token of the platform's own Google account */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $searchConsoleToken = null;

    #[ORM\Column(length: 20, enumType: SearchConsoleStatus::class)]
    private SearchConsoleStatus $searchConsoleStatus = SearchConsoleStatus::NotConnected;

    /** Last result of the Search Console setup, shown in the admin */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $searchConsoleMessage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $googleCheckedAt = null;

    /** Link to the client's Google Business Profile (Maps listing), once it exists */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Assert\Regex('#^https://(www\.)?(google\.[a-z.]+|maps\.app\.goo\.gl|g\.page|goo\.gl)/#', message: 'Use the share link of the Google Maps listing.')]
    private ?string $googleBusinessUrl = null;

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

    public function getStreetAddress(): ?string
    {
        return $this->streetAddress;
    }

    public function setStreetAddress(?string $streetAddress): static
    {
        $this->changeAddress($this->streetAddress, $streetAddress);

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->changeAddress($this->postalCode, $postalCode);

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->changeAddress($this->city, $city);

        return $this;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $country = strtoupper(trim((string) $country)) ?: 'ES';
        if ($country !== $this->country) {
            $this->country = $country;
            $this->clearCoordinates();
        }

        return $this;
    }

    public function isShowStreetAddress(): bool
    {
        return $this->showStreetAddress;
    }

    public function setShowStreetAddress(bool $showStreetAddress): static
    {
        $this->showStreetAddress = $showStreetAddress;

        return $this;
    }

    public function hasAddress(): bool
    {
        return null !== $this->city || null !== $this->streetAddress;
    }

    /** Full address on one line, e.g. "Calle Mayor 1, 03730 Jávea" */
    public function getAddressLine(): string
    {
        return self::formatAddress($this->streetAddress, $this->postalCode, $this->city);
    }

    /** What the website may show: the full address, or only the town. */
    public function getPublicAddressLine(): string
    {
        return $this->showStreetAddress
            ? $this->getAddressLine()
            : self::formatAddress(null, $this->postalCode, $this->city);
    }

    public static function formatAddress(?string $street, ?string $postalCode, ?string $city): string
    {
        $town = trim(($postalCode ?? '').' '.($city ?? ''));

        return implode(', ', array_filter([trim((string) $street), $town]));
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function hasCoordinates(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }

    public function setCoordinates(?float $latitude, ?float $longitude): static
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;

        return $this;
    }

    /** What to search for on Google Maps: the business listing if known, else the (public) address. */
    public function getMapQuery(): string
    {
        if ($this->showStreetAddress && $this->streetAddress) {
            return $this->name.', '.$this->getAddressLine();
        }

        return $this->getPublicAddressLine() ?: (string) $this->serviceArea;
    }

    /** Opens Google Maps (app or website), see https://developers.google.com/maps/documentation/urls */
    public function getGoogleMapsUrl(): ?string
    {
        if ($this->googleBusinessUrl) {
            return $this->googleBusinessUrl;
        }

        $query = $this->getMapQuery();

        return '' === $query ? null : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query);
    }

    /** Embeddable map, loaded only after the visitor clicks (Google sets cookies) */
    public function getGoogleMapsEmbedUrl(): ?string
    {
        if ($this->hasCoordinates() && $this->showStreetAddress) {
            $query = \sprintf('%F,%F', $this->latitude, $this->longitude);
        } else {
            $query = $this->getPublicAddressLine() ?: (string) $this->serviceArea;
        }

        // A town-only map is zoomed out, so it shows the area rather than a house
        $zoom = $this->showStreetAddress && $this->streetAddress ? 15 : 12;

        return '' === $query ? null : \sprintf('https://maps.google.com/maps?q=%s&z=%d&output=embed', rawurlencode($query), $zoom);
    }

    private function changeAddress(?string &$field, ?string $value): void
    {
        $value = trim((string) $value) ?: null;
        if ($value !== $field) {
            $field = $value;
            // Coordinates belong to the old address; they are looked up again
            $this->clearCoordinates();
        }
    }

    private function clearCoordinates(): void
    {
        $this->latitude = null;
        $this->longitude = null;
    }

    public function getLogoName(): ?string
    {
        return $this->logoName;
    }

    public function setLogoName(?string $logoName): static
    {
        $this->logoName = $logoName;

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

    public function getSearchConsoleToken(): ?string
    {
        return $this->searchConsoleToken;
    }

    public function setSearchConsoleToken(?string $searchConsoleToken): static
    {
        $this->searchConsoleToken = $searchConsoleToken;

        return $this;
    }

    public function getSearchConsoleStatus(): SearchConsoleStatus
    {
        return $this->searchConsoleStatus;
    }

    public function getSearchConsoleMessage(): ?string
    {
        return $this->searchConsoleMessage;
    }

    public function setSearchConsoleStatus(SearchConsoleStatus $status, ?string $message = null): static
    {
        $this->searchConsoleStatus = $status;
        $this->searchConsoleMessage = null === $message ? null : mb_substr($message, 0, 500);
        $this->googleCheckedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getGoogleCheckedAt(): ?\DateTimeImmutable
    {
        return $this->googleCheckedAt;
    }

    public function getGoogleBusinessUrl(): ?string
    {
        return $this->googleBusinessUrl;
    }

    public function setGoogleBusinessUrl(?string $googleBusinessUrl): static
    {
        $this->googleBusinessUrl = trim((string) $googleBusinessUrl) ?: null;

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

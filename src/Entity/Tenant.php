<?php

declare(strict_types=1);

namespace App\Entity;

use App\Billing\WorkdayCalendar;
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

    /** Filled automatically from the address (OpenStreetMap Nominatim) */
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

    /** @var list<string> Trade values: what the client can be found for in the platform search */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\All([new Assert\Choice(callback: [Trade::class, 'values'])])]
    private array $trades = [];

    // Published reviews, kept up to date by App\EventListener\ReviewStatsListener.
    // Stored here so the platform search never has to read other clients' reviews.

    #[ORM\Column]
    private int $reviewCount = 0;

    #[ORM\Column(nullable: true)]
    private ?float $reviewAverage = null;

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

    // Billing (Mollie), see App\Billing\BillingService

    /** Where invoices and payment reminders go; falls back to the enquiry address */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $billingEmail = null;

    /** Free account (demo, friends): never charged, never taken offline for billing */
    #[ORM\Column]
    private bool $billingExempt = false;

    /** Monthly price in cents excluding VAT; null uses BILLING_MONTHLY_PRICE */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $monthlyPrice = null;

    /** First day the client is charged; everything before is free. Null: derived from createdAt */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $billingStartsOn = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $mollieCustomerId = null;

    /** Authorisation to charge the client automatically (card or SEPA direct debit) */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $mollieMandateId = null;

    /** e.g. "Visa •••• 4242" or "SEPA direct debit NL55 •••• 0123" */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mandateDescription = null;

    /** Mollie subscription that charges the client every month; null when there is none running */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $mollieSubscriptionId = null;

    /** Latest checkout started by the client, checked when they come back from Mollie */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $lastCheckoutPaymentId = null;

    /** Secret part of the payment link in reminder emails (works without logging in) */
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $billingToken = null;

    /** Set when the site is taken offline for non-payment */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    /** @var Collection<int, Domain> */
    #[ORM\OneToMany(targetEntity: Domain::class, mappedBy: 'tenant', cascade: ['persist'], orphanRemoval: true)]
    private Collection $domains;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->domains = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->billingStartsOn = WorkdayCalendar::firstBillingDay($this->createdAt);
        $this->billingToken = self::newBillingToken();
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
        if ($showStreetAddress !== $this->showStreetAddress) {
            $this->showStreetAddress = $showStreetAddress;
            // Hidden street: the map position is looked up again at town level, so it never points at a home
            $this->clearCoordinates();
        }

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

    /** Zoom level for the map: the street, or the whole town when the street is hidden */
    public function getMapZoom(): int
    {
        return $this->showStreetAddress && $this->streetAddress ? 17 : 13;
    }

    /** The location on openstreetmap.org (also opens in map apps on phones) */
    public function getMapUrl(): ?string
    {
        if (!$this->hasCoordinates()) {
            return null;
        }

        return \sprintf(
            'https://www.openstreetmap.org/?mlat=%2$.6F&mlon=%3$.6F#map=%1$d/%2$.6F/%3$.6F',
            $this->getMapZoom(),
            $this->latitude,
            $this->longitude,
        );
    }

    /** Embeddable OpenStreetMap with a marker, see https://wiki.openstreetmap.org/wiki/Export */
    public function getMapEmbedUrl(): ?string
    {
        if (!$this->hasCoordinates()) {
            return null;
        }

        // Half the visible area in degrees: a few streets, or the whole town
        [$dLat, $dLon] = 17 === $this->getMapZoom() ? [0.0025, 0.004] : [0.03, 0.05];

        return \sprintf(
            'https://www.openstreetmap.org/export/embed.html?bbox=%.6F%%2C%.6F%%2C%.6F%%2C%.6F&layer=mapnik&marker=%.6F%%2C%.6F',
            $this->longitude - $dLon,
            $this->latitude - $dLat,
            $this->longitude + $dLon,
            $this->latitude + $dLat,
            $this->latitude,
            $this->longitude,
        );
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

    /** @return list<string> */
    public function getTrades(): array
    {
        return $this->trades;
    }

    /** @param list<string>|null $trades */
    public function setTrades(?array $trades): static
    {
        $this->trades = array_values(array_unique($trades ?? []));

        return $this;
    }

    public function hasTrade(string $trade): bool
    {
        return \in_array($trade, $this->trades, true);
    }

    public function getReviewCount(): int
    {
        return $this->reviewCount;
    }

    public function getReviewAverage(): ?float
    {
        return $this->reviewAverage;
    }

    /** e.g. "RG" for "Reformas García", shown when there is no logo */
    public function getInitials(): string
    {
        $words = preg_split('/\s+/u', trim($this->name), -1, \PREG_SPLIT_NO_EMPTY) ?: [''];

        return mb_strtoupper(implode('', array_map(static fn (string $word) => mb_substr($word, 0, 1), \array_slice($words, 0, 2))));
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

    public function getBillingEmail(): ?string
    {
        return $this->billingEmail;
    }

    public function setBillingEmail(?string $billingEmail): static
    {
        $this->billingEmail = $billingEmail;

        return $this;
    }

    /** Address for invoices and reminders */
    public function getBillingRecipient(): ?string
    {
        return $this->billingEmail ?: $this->email;
    }

    public function isBillingExempt(): bool
    {
        return $this->billingExempt;
    }

    public function setBillingExempt(bool $billingExempt): static
    {
        $this->billingExempt = $billingExempt;

        return $this;
    }

    public function getMonthlyPrice(): ?int
    {
        return $this->monthlyPrice;
    }

    public function setMonthlyPrice(?int $monthlyPrice): static
    {
        $this->monthlyPrice = $monthlyPrice;

        return $this;
    }

    public function getBillingStartsOn(): \DateTimeImmutable
    {
        return $this->billingStartsOn ?? WorkdayCalendar::firstBillingDay($this->createdAt);
    }

    public function setBillingStartsOn(?\DateTimeImmutable $billingStartsOn): static
    {
        $this->billingStartsOn = $billingStartsOn;

        return $this;
    }

    public function getMollieCustomerId(): ?string
    {
        return $this->mollieCustomerId;
    }

    public function setMollieCustomerId(?string $mollieCustomerId): static
    {
        $this->mollieCustomerId = $mollieCustomerId;

        return $this;
    }

    public function getMollieMandateId(): ?string
    {
        return $this->mollieMandateId;
    }

    public function hasMandate(): bool
    {
        return null !== $this->mollieMandateId;
    }

    public function setMandate(?string $mandateId, ?string $description): static
    {
        $this->mollieMandateId = $mandateId;
        $this->mandateDescription = $mandateId ? $description : null;

        return $this;
    }

    public function getMandateDescription(): ?string
    {
        return $this->mandateDescription;
    }

    public function getMollieSubscriptionId(): ?string
    {
        return $this->mollieSubscriptionId;
    }

    public function setMollieSubscriptionId(?string $mollieSubscriptionId): static
    {
        $this->mollieSubscriptionId = $mollieSubscriptionId;

        return $this;
    }

    public function getLastCheckoutPaymentId(): ?string
    {
        return $this->lastCheckoutPaymentId;
    }

    public function setLastCheckoutPaymentId(?string $lastCheckoutPaymentId): static
    {
        $this->lastCheckoutPaymentId = $lastCheckoutPaymentId;

        return $this;
    }

    /** Clients created before billing existed get a token on first use (flush afterwards) */
    public function getBillingToken(): string
    {
        return $this->billingToken ??= self::newBillingToken();
    }

    public function getSuspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function isSuspended(): bool
    {
        return null !== $this->suspendedAt;
    }

    public function suspend(\DateTimeImmutable $now): static
    {
        $this->suspendedAt ??= $now;

        return $this;
    }

    public function unsuspend(): static
    {
        $this->suspendedAt = null;

        return $this;
    }

    private static function newBillingToken(): string
    {
        return bin2hex(random_bytes(24));
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

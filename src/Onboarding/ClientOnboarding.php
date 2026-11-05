<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Entity\Domain;
use App\Entity\SearchConsoleStatus;
use App\Entity\Signup;
use App\Entity\Tenant;
use App\Entity\User;
use App\Google\GoogleApiException;
use App\Map\Geocoder;
use App\Map\GeocodingException;
use App\Google\SearchConsole;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Turns an approved sign-up into a working client site, in one go:
 *
 *  - client (Tenant) with the details from the sign-up
 *  - domain(s): their own domain + www., or <slug>.CLIENT_SUBDOMAIN_BASE
 *  - client login (with the password they chose, or a "set your password" link)
 *  - welcome email
 *  - map position of the address (OpenStreetMap) and the Google Search Console property
 *
 * Used by the admin (Sign-ups → Approve, Clients → Connect Google) and the
 * console (app:client:onboard, app:client:approve, app:google:connect).
 */
final readonly class ClientOnboarding
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantRepository $tenants,
        private ValidatorInterface $validator,
        private SluggerInterface $slugger,
        private UserPasswordHasherInterface $passwordHasher,
        private OnboardingMailer $mailer,
        private Geocoder $geocoder,
        private SearchConsole $searchConsole,
        #[Autowire(env: 'default::CLIENT_SUBDOMAIN_BASE')]
        private ?string $subdomainBase,
    ) {
    }

    /**
     * @throws OnboardingException when the sign-up cannot be approved; nothing is saved then
     */
    public function approve(Signup $signup): OnboardingResult
    {
        if (!$signup->isPending()) {
            throw new OnboardingException(['This sign-up was already '.strtolower($signup->getStatus()->label()).'.']);
        }

        $problems = $this->violations($signup);
        if ([] !== $problems) {
            throw new OnboardingException($problems);
        }

        $tenant = $this->createTenant($signup);
        $user = (new User())->setEmail($signup->getEmail())->setTenant($tenant);

        if ($signup->hasPassword()) {
            $user->setPassword($signup->getPasswordHash());
            $setupToken = null;
        } else {
            // Unusable until the client sets one via the link in the welcome email
            $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
            $setupToken = $user->createPasswordSetupToken();
        }

        $problems = [...$this->violations($tenant), ...$this->violations($user)];
        foreach ($tenant->getDomains() as $domain) {
            $problems = [...$problems, ...$this->violations($domain, $domain->getHostname())];
        }
        if ([] !== $problems) {
            throw new OnboardingException(array_values(array_unique($problems)));
        }

        $signup->approve($tenant);
        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $result = new OnboardingResult($tenant);
        $result->add(OnboardingResult::OK, 'Client created', \sprintf('%s (%s)', $tenant->getName(), $tenant->getSlug()));
        $result->add(OnboardingResult::OK, 'Login created', $user->getEmail());

        $hostnames = $tenant->getDomains()->map(static fn (Domain $d) => $d->getHostname())->toArray();
        if ([] === $hostnames) {
            $result->add(OnboardingResult::WARNING, 'Domain', 'No domain yet: add one under Domains, then run "Connect Google".');
        } else {
            $result->add(OnboardingResult::OK, 'Domain', implode(', ', $hostnames).($signup->getDomain() ? ' (point the DNS A record to the server)' : ''));
        }

        $this->mailer->sendWelcome($user, $tenant, $setupToken, $result);
        $this->connectGoogle($tenant, $result);

        return $result;
    }

    /**
     * (Re)runs the Google setup for an existing client. Safe to run again and
     * again: steps that are already done are skipped.
     */
    public function connectGoogle(Tenant $tenant, ?OnboardingResult $result = null): OnboardingResult
    {
        $result ??= new OnboardingResult($tenant);

        $this->connectMaps($tenant, $result);
        $this->entityManager->flush();

        $this->connectSearchConsole($tenant, $result);
        $this->entityManager->flush();

        if (!$tenant->getGoogleBusinessUrl()) {
            $result->add(
                OnboardingResult::SKIPPED,
                'Google Business Profile',
                'Google does not allow creating Maps listings through its API: create or claim it at https://business.google.com/create (Google verifies it by post, phone or video), then paste its share link under Clients → Look & Google.',
            );
        }

        return $result;
    }

    /** Only the map position, e.g. after the client changed their address in Settings. */
    public function locate(Tenant $tenant): OnboardingResult
    {
        $result = new OnboardingResult($tenant);
        $this->connectMaps($tenant, $result);
        $this->entityManager->flush();

        return $result;
    }

    private function connectMaps(Tenant $tenant, OnboardingResult $result): void
    {
        if (!$tenant->hasAddress()) {
            $result->add(OnboardingResult::WARNING, 'Map', 'No address: the website shows the work area instead.');

            return;
        }
        if ($tenant->hasCoordinates()) {
            $result->add(OnboardingResult::SKIPPED, 'Map', 'Location already known.');

            return;
        }
        try {
            $found = $this->geocoder->geocode($tenant);
        } catch (GeocodingException $e) {
            $result->add(OnboardingResult::ERROR, 'Map', $e->getMessage().' Retried by app:google:connect --all.');

            return;
        }

        match (true) {
            null === $found => $result->add(OnboardingResult::WARNING, 'Map', 'OpenStreetMap could not find "'.$tenant->getAddressLine().'": check the address. The website shows no map until then.'),
            $found->exact || !$tenant->isShowStreetAddress() => $result->add(OnboardingResult::OK, 'Map', 'Located on OpenStreetMap: '.$found->placeName),
            default => $result->add(OnboardingResult::WARNING, 'Map', 'Street not found on OpenStreetMap, the map shows the town: '.$found->placeName),
        };
    }

    private function connectSearchConsole(Tenant $tenant, OnboardingResult $result): void
    {
        if (!$this->searchConsole->isConfigured()) {
            $result->add(OnboardingResult::SKIPPED, 'Search Console', 'GOOGLE_SERVICE_ACCOUNT_KEY not set: see README → Google setup.');

            return;
        }
        if (SearchConsoleStatus::Connected === $tenant->getSearchConsoleStatus()) {
            $result->add(OnboardingResult::SKIPPED, 'Search Console', 'Already connected.');

            return;
        }
        if (null === $tenant->getPrimaryDomain()) {
            $result->add(OnboardingResult::WARNING, 'Search Console', 'Needs a domain first.');

            return;
        }

        try {
            // The site must serve the new tag before Google can check it, hence the flush
            if ($this->searchConsole->ensureVerificationToken($tenant)) {
                $this->entityManager->flush();
            }
            $warnings = $this->searchConsole->verifyAndRegister($tenant);
        } catch (GoogleApiException $e) {
            $tenant->setSearchConsoleStatus(SearchConsoleStatus::Failed, $e->getMessage());
            $warnings = [];
        }

        $result->add(match ($tenant->getSearchConsoleStatus()) {
            SearchConsoleStatus::Connected => OnboardingResult::OK,
            SearchConsoleStatus::AwaitingSite => OnboardingResult::WARNING,
            default => OnboardingResult::ERROR,
        }, 'Search Console', (string) $tenant->getSearchConsoleMessage());

        foreach ($warnings as $warning) {
            $result->add(OnboardingResult::WARNING, 'Search Console', $warning);
        }
    }

    private function createTenant(Signup $signup): Tenant
    {
        $tenant = (new Tenant())
            ->setName($signup->getBusinessName())
            ->setSlug($this->uniqueSlug($signup->getBusinessName()))
            ->setLocales($signup->getLocales())
            ->setDefaultLocale($signup->getDefaultLocale())
            ->setPhone($signup->getPhone())
            ->setWhatsapp($signup->getWhatsapp() ?? $signup->getPhone())
            ->setEmail($signup->getEmail())
            ->setStreetAddress($signup->getStreetAddress())
            ->setPostalCode($signup->getPostalCode())
            ->setCity($signup->getCity())
            ->setCountry($signup->getCountry())
            ->setServiceArea($signup->getServiceArea() ?? $signup->getCity())
            // Sensible defaults for the legal notice; the client can change them in Settings
            ->setLegalName($signup->getContactName())
            ->setTaxId($signup->getTaxId())
            ->setLegalAddress($signup->getAddressLine());

        if ($own = $signup->getDomain()) {
            $tenant->addDomain((new Domain())->setHostname($own)->setIsPrimary(true));
            if (!str_starts_with($own, 'www.')) {
                $tenant->addDomain((new Domain())->setHostname('www.'.$own));
            }
        } elseif ($base = trim((string) $this->subdomainBase, ". \t")) {
            $tenant->addDomain((new Domain())->setHostname($tenant->getSlug().'.'.$base)->setIsPrimary(true));
        }

        return $tenant;
    }

    private function uniqueSlug(string $name): string
    {
        $base = substr(trim($this->slugger->slug($name)->lower()->toString(), '-'), 0, 70) ?: 'client';
        $slug = $base;

        for ($i = 2; null !== $this->tenants->findOneBy(['slug' => $slug]); ++$i) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /** @return list<string> */
    private function violations(object $object, ?string $prefix = null): array
    {
        $messages = [];
        foreach ($this->validator->validate($object) as $violation) {
            $label = $prefix ?? $violation->getPropertyPath();
            $messages[] = ('' !== $label ? $label.': ' : '').$violation->getMessage();
        }

        return array_values(array_unique($messages));
    }
}

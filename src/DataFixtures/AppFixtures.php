<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Domain;
use App\Entity\Project;
use App\Entity\Review;
use App\Entity\Service;
use App\Entity\Signup;
use App\Entity\Tenant;
use App\Entity\Trade;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo data for local development: two client sites with reviews, three logins and two sign-ups.
 * Load with: ddev console doctrine:fixtures:load
 */
final class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $jan = (new Tenant())
            ->setName("Jan's Klusbedrijf")
            ->setSlug('jans-klusbedrijf')
            ->setLocales(['en', 'es', 'nl'])
            ->setDefaultLocale('en')
            ->setPrimaryColor('#d9480f')
            ->setPhone('+34 600 111 222')
            ->setWhatsapp('+34 600 111 222')
            ->setEmail('jan@example.com')
            ->setServiceArea('Torrent, Valencia and surroundings')
            ->setTrades([Trade::Handyman->value, Trade::Painting->value, Trade::Tiling->value, Trade::Garden->value])
            ->setStreetAddress('Calle Ejemplo 1')
            ->setPostalCode('46900')
            ->setCity('Torrent')
            ->setCoordinates(39.4371, -0.4655)
            ->setTagline([
                'en' => 'Reliable Dutch handyman in Valencia',
                'es' => 'Manitas holandés de confianza en Valencia',
                'nl' => 'Betrouwbare Nederlandse klusjesman in Valencia',
            ])
            ->setIntro([
                'en' => 'Small repairs, renovations and maintenance. Clear quotes, on time, and we speak your language.',
                'es' => 'Pequeñas reparaciones, reformas y mantenimiento. Presupuestos claros y puntualidad.',
                'nl' => 'Kleine reparaties, verbouwingen en onderhoud. Duidelijke offertes, op tijd, en we spreken je taal.',
            ])
            ->setAbout([
                'en' => "Jan moved to Spain in 2015 and has been helping homeowners ever since.",
                'nl' => 'Jan verhuisde in 2015 naar Spanje en helpt sindsdien huiseigenaren met hun klussen.',
            ])
            ->setLegalName('Jan de Vries')
            ->setTaxId('X0000000T')
            ->setLegalAddress('Calle Ejemplo 1, 46900 Torrent, Valencia')
            ->addDomain((new Domain())->setHostname('jans-klusbedrijf.ddev.site')->setIsPrimary(true));
        $manager->persist($jan);

        $this->addServices($manager, $jan, [
            ['en' => 'Repairs & maintenance', 'es' => 'Reparaciones y mantenimiento', 'nl' => 'Reparaties & onderhoud'],
            ['en' => 'Painting', 'es' => 'Pintura', 'nl' => 'Schilderwerk'],
            ['en' => 'Tiling', 'es' => 'Alicatado', 'nl' => 'Tegelwerk'],
            ['en' => 'Garden & pool', 'es' => 'Jardín y piscina', 'nl' => 'Tuin & zwembad'],
        ]);
        $this->addProjects($manager, $jan, [
            ['en' => 'New bathroom tiles in Torrent', 'nl' => 'Nieuwe badkamertegels in Torrent'],
            ['en' => 'Terrace painted in Paterna', 'nl' => 'Terras geschilderd in Paterna'],
        ]);
        $this->addReviews($manager, $jan, [
            ['Maria', 'Torrent', 5, 'Jan retiled our bathroom in three days. Tidy, on time and exactly the price he quoted.', true],
            ['Peter', 'Paterna', 4, 'Good painting job on our terrace. Took a day longer because of the rain, but he kept us informed.', true],
            ['Spam Bot', null, 1, 'Cheap watches at example dot com, best prices online.', false],
        ]);

        $javea = (new Tenant())
            ->setName('Jávea Handyman Services')
            ->setSlug('javea-handyman')
            ->setLocales(['en', 'es'])
            ->setDefaultLocale('en')
            ->setPrimaryColor('#1971c2')
            ->setPhone('+34 600 333 444')
            ->setWhatsapp('+34 600 333 444')
            ->setEmail('javea@example.com')
            ->setServiceArea('Jávea, Dénia, Moraira')
            ->setTrades([Trade::Handyman->value, Trade::Electrical->value, Trade::Pool->value])
            ->setPostalCode('03730')
            ->setCity('Jávea')
            ->setShowStreetAddress(false)
            // Town centre: the street is hidden, so the map only shows the town
            ->setCoordinates(38.7893, 0.1664)
            ->setTagline([
                'en' => 'Your handyman on the Costa Blanca',
                'es' => 'Su manitas en la Costa Blanca',
            ])
            ->setIntro([
                'en' => 'Villa maintenance, key holding and repairs for residents and holiday homes.',
                'es' => 'Mantenimiento de villas, custodia de llaves y reparaciones.',
            ])
            ->setLegalName('Costa Handyman S.L.')
            ->setTaxId('B00000000')
            ->addDomain((new Domain())->setHostname('handyman-javea.ddev.site')->setIsPrimary(true));
        $manager->persist($javea);

        $this->addServices($manager, $javea, [
            ['en' => 'Villa maintenance', 'es' => 'Mantenimiento de villas'],
            ['en' => 'Key holding', 'es' => 'Custodia de llaves'],
            ['en' => 'Small electrical jobs', 'es' => 'Pequeños trabajos eléctricos'],
        ]);
        $this->addReviews($manager, $javea, [
            ['Sue', 'Jávea', 5, 'They look after our villa while we are in the UK. Always reachable and very reliable.', true],
        ]);

        // Sign-ups waiting for approval (admin → Sign-ups, or: ddev console app:client:approve)
        $manager->persist((new Signup())
            ->setBusinessName('Pieters Tegelwerk')
            ->setContactName('Piet Pieters')
            ->setEmail('piet@example.com')
            ->setPhone('+34 600 555 666')
            ->setStreetAddress('Avenida del Mar 12')
            ->setPostalCode('03590')
            ->setCity('Altea')
            ->setServiceArea('Altea, Albir, Benidorm')
            ->setDomain('pieters-tegelwerk.ddev.site')
            ->setLocales(['nl', 'en', 'es'])
            ->setDefaultLocale('nl')
            ->setMessage('Tiling and bathroom renovations, 15 years of experience.')
            ->setPasswordHash($this->passwordHasher->hashPassword(new User(), 'piet')));
        $manager->persist((new Signup())
            ->setBusinessName('Costa Pool Care')
            ->setContactName('Emma Smith')
            ->setEmail('emma@example.com')
            ->setPhone('+34 600 777 888')
            ->setStreetAddress('Calle Sol 4')
            ->setPostalCode('03724')
            ->setCity('Moraira')
            ->setLocales(['en', 'es'])
            ->setPasswordHash($this->passwordHasher->hashPassword(new User(), 'emma')));

        $this->addUser($manager, 'admin@example.com', 'admin', null, [User::ROLE_SUPER_ADMIN]);
        $this->addUser($manager, 'jan@example.com', 'jan', $jan);
        $this->addUser($manager, 'javea@example.com', 'javea', $javea);

        $manager->flush();
    }

    /** @param list<array<string, string>> $titles */
    private function addServices(ObjectManager $manager, Tenant $tenant, array $titles): void
    {
        foreach ($titles as $position => $title) {
            $manager->persist((new Service())->setTenant($tenant)->setTitle($title)->setPosition($position));
        }
    }

    /** @param list<array<string, string>> $titles */
    private function addProjects(ObjectManager $manager, Tenant $tenant, array $titles): void
    {
        foreach ($titles as $i => $title) {
            $manager->persist((new Project())
                ->setTenant($tenant)
                ->setTitle($title)
                ->setCompletedAt(new \DateTimeImmutable(\sprintf('-%d weeks', ($i + 1) * 3))));
        }
    }

    /** @param list<array{string, ?string, int, string, bool}> $reviews name, town, stars, text, published */
    private function addReviews(ObjectManager $manager, Tenant $tenant, array $reviews): void
    {
        foreach ($reviews as [$name, $town, $rating, $text, $published]) {
            $manager->persist((new Review())
                ->setTenant($tenant)
                ->setAuthorName($name)
                ->setTown($town)
                ->setRating($rating)
                ->setText($text)
                ->setPublished($published));
        }
    }

    /** @param list<string> $roles */
    private function addUser(ObjectManager $manager, string $email, string $password, ?Tenant $tenant, array $roles = []): void
    {
        $user = (new User())->setEmail($email)->setTenant($tenant)->setRoles($roles);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $manager->persist($user);
    }
}

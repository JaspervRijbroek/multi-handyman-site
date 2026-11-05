<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Signup;
use App\Entity\Tenant;
use App\Onboarding\ClientOnboarding;
use App\Onboarding\OnboardingException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:client:onboard',
    description: 'Add a new client in one go: client, domain, login, welcome email and Google setup',
)]
final class OnboardClientCommand extends Command
{
    /** option => [question, required] */
    private const array FIELDS = [
        'business' => ['Business name', true],
        'contact' => ['Contact person (owner)', true],
        'email' => ['Email (login + enquiries)', true],
        'phone' => ['Phone', true],
        'whatsapp' => ['WhatsApp number (empty = same as phone)', false],
        'street' => ['Street and number', true],
        'postal-code' => ['Postal code', true],
        'city' => ['Town', true],
        'country' => ['Country code', true],
        'area' => ['Work area (empty = the town)', false],
        'domain' => ['Own domain, e.g. example.com (empty = none yet)', false],
        'languages' => ['Languages, comma separated', true],
        'main-language' => ['Main language', true],
        'tax-id' => ['NIF / NIE / CIF (optional)', false],
    ];

    public function __construct(
        private readonly ClientOnboarding $onboarding,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        foreach (self::FIELDS as $name => [$question]) {
            $this->addOption($name, null, InputOption::VALUE_REQUIRED, $question, match ($name) {
                'country' => 'ES',
                'languages' => 'en,es',
                default => null,
            });
        }

        $this->setHelp(<<<'HELP'
            Asks for anything you did not pass as an option, then creates the client
            right away (no approval step), emails the client a link to choose a
            password, puts the address on the map (OpenStreetMap) and connects Google
            Search Console.

            Interactive:
              <info>php bin/console app:client:onboard</info>

            All at once (e.g. from a script):
              <info>php bin/console app:client:onboard -n --business="Jan's Klusbedrijf" --contact="Jan de Vries" \
                --email=jan@example.com --phone="+34 600 111 222" --street="Calle Mayor 1" \
                --postal-code=46900 --city=Torrent --domain=jansklusbedrijf.com --languages=en,es,nl --main-language=nl</info>

            Clients who signed up themselves on /register: use <info>app:client:approve</info>.
            HELP);
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('New client');

        foreach (self::FIELDS as $name => [$question, $required]) {
            if (null !== $input->getOption($name) && ('country' !== $name && 'languages' !== $name)) {
                continue;
            }

            $default = $input->getOption($name) ?? ('main-language' === $name ? explode(',', (string) $input->getOption('languages'))[0] : null);
            $input->setOption($name, $io->ask($question, $default, static function (?string $value) use ($required): ?string {
                if ($required && '' === trim((string) $value)) {
                    throw new \RuntimeException('This one is required.');
                }

                return $value;
            }));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $option = static fn (string $name): ?string => $input->getOption($name);

        $locales = array_values(array_filter(array_map('trim', explode(',', strtolower((string) $option('languages'))))));
        $signup = (new Signup())
            ->setBusinessName($option('business'))
            ->setContactName($option('contact'))
            ->setEmail($option('email'))
            ->setPhone($option('phone'))
            ->setWhatsapp($option('whatsapp'))
            ->setStreetAddress($option('street'))
            ->setPostalCode($option('postal-code'))
            ->setCity($option('city'))
            ->setCountry($option('country'))
            ->setServiceArea($option('area'))
            ->setDomain($option('domain'))
            ->setLocales($locales)
            ->setDefaultLocale(strtolower((string) ($option('main-language') ?? $locales[0] ?? 'en')))
            ->setTaxId($option('tax-id'));

        $violations = $this->validator->validate($signup);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error(\sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()));
            }
            $io->note('Languages: '.implode(', ', Tenant::SUPPORTED_LOCALES));

            return Command::FAILURE;
        }

        $this->entityManager->persist($signup);
        $this->entityManager->flush();

        try {
            $result = $this->onboarding->approve($signup);
        } catch (OnboardingException $e) {
            // Nothing else was saved: drop the sign-up too, so the command can simply be run again
            $this->entityManager->remove($signup);
            $this->entityManager->flush();
            OnboardingOutput::problems($io, $e);

            return Command::FAILURE;
        }

        OnboardingOutput::result($io, $result);
        $io->success(\sprintf('%s is onboarded.', $result->tenant->getName()));

        return Command::SUCCESS;
    }
}

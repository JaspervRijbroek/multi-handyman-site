<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Signup;
use App\Onboarding\ClientOnboarding;
use App\Onboarding\OnboardingException;
use App\Repository\SignupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:client:approve',
    description: 'List sign-ups waiting for approval, and approve (or reject) one',
)]
final class ApproveSignupCommand extends Command
{
    public function __construct(
        private readonly SignupRepository $signups,
        private readonly ClientOnboarding $onboarding,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'Sign-up number; leave out to pick from the list')
            ->addOption('reject', null, InputOption::VALUE_NONE, 'Reject instead of approve')
            ->setHelp(<<<'HELP'
                Approving creates the client, its domain(s) and login (with the password
                the client chose), sends the welcome email and connects Google.

                  <info>php bin/console app:client:approve</info>          list and pick
                  <info>php bin/console app:client:approve 12</info>       approve sign-up #12
                  <info>php bin/console app:client:approve 12 --reject</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $signup = $this->pick($io, $input->getArgument('id'), $input->isInteractive());
        if (null === $signup) {
            return Command::SUCCESS;
        }
        if (!$signup->isPending()) {
            $io->error(\sprintf('Sign-up #%d was already %s.', $signup->getId(), strtolower($signup->getStatus()->label())));

            return Command::FAILURE;
        }

        $this->show($io, $signup);

        if ($input->getOption('reject')) {
            if (!$io->confirm('Reject this sign-up?', false)) {
                return Command::SUCCESS;
            }
            $signup->reject();
            $this->entityManager->flush();
            $io->success('Rejected.');

            return Command::SUCCESS;
        }

        if (!$io->confirm('Approve and set up this client?', true)) {
            return Command::SUCCESS;
        }

        try {
            $result = $this->onboarding->approve($signup);
        } catch (OnboardingException $e) {
            OnboardingOutput::problems($io, $e);

            return Command::FAILURE;
        }

        OnboardingOutput::result($io, $result);
        $io->success(\sprintf('%s is onboarded.', $result->tenant->getName()));

        return Command::SUCCESS;
    }

    private function pick(SymfonyStyle $io, ?string $id, bool $interactive): ?Signup
    {
        if (null !== $id) {
            $signup = $this->signups->find((int) $id);
            if (null === $signup) {
                $io->error(\sprintf('No sign-up #%s.', $id));
            }

            return $signup;
        }

        $pending = $this->signups->findPending();
        if ([] === $pending) {
            $io->success('No sign-ups waiting for approval.');

            return null;
        }

        $io->table(['#', 'Received', 'Business', 'Contact', 'Email', 'Town', 'Domain'], array_map(static fn (Signup $s) => [
            $s->getId(),
            $s->getCreatedAt()->format('d-m-Y H:i'),
            $s->getBusinessName(),
            $s->getContactName(),
            $s->getEmail(),
            $s->getCity(),
            $s->getDomain() ?? '–',
        ], $pending));

        if (!$interactive) {
            return null;
        }

        $choices = [];
        foreach ($pending as $signup) {
            $choices[(string) $signup->getId()] = $signup->getBusinessName();
        }

        return $this->signups->find((int) $io->choice('Which sign-up?', $choices));
    }

    private function show(SymfonyStyle $io, Signup $signup): void
    {
        $io->definitionList(
            ['Business' => $signup->getBusinessName()],
            ['Contact' => $signup->getContactName()],
            ['Email / login' => $signup->getEmail()],
            ['Phone' => $signup->getPhone().($signup->getWhatsapp() ? ' (WhatsApp '.$signup->getWhatsapp().')' : '')],
            ['Address' => $signup->getAddressLine().', '.$signup->getCountry()],
            ['Work area' => $signup->getServiceArea() ?? '–'],
            ['Domain' => $signup->getDomain() ?? '–'],
            ['Languages' => strtoupper(implode(', ', $signup->getLocales())).' (main: '.strtoupper($signup->getDefaultLocale()).')'],
            ['Message' => $signup->getMessage() ?? '–'],
        );
    }
}

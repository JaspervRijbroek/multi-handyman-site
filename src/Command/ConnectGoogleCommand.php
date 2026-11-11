<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SearchConsoleStatus;
use App\Entity\Tenant;
use App\Onboarding\ClientOnboarding;
use App\Repository\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:google:connect',
    description: 'Look up map positions (OpenStreetMap) and connect Google Search Console (retries what is not done yet)',
)]
final class ConnectGoogleCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly ClientOnboarding $onboarding,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'Client slug')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every active client that is not fully connected yet')
            ->setHelp(<<<'HELP'
                Search Console can only verify a site once its domain points to this server,
                which is often a few days after onboarding. app:schedule:run runs this with
                --all every hour, so it is picked up automatically.

                One client:
                  <info>php bin/console app:google:connect jans-klusbedrijf</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($slug = $input->getArgument('slug')) {
            $tenant = $this->tenants->findOneBy(['slug' => $slug]);
            if (null === $tenant) {
                $io->error(\sprintf('No client with slug "%s".', $slug));

                return Command::FAILURE;
            }
            $tenants = [$tenant];
        } elseif ($input->getOption('all')) {
            $tenants = array_filter(
                $this->tenants->findBy(['active' => true]),
                static fn (Tenant $t) => SearchConsoleStatus::Connected !== $t->getSearchConsoleStatus() || ($t->hasAddress() && !$t->hasCoordinates()),
            );
        } else {
            $io->error('Pass a client slug, or --all.');

            return Command::INVALID;
        }

        $failed = false;
        foreach ($tenants as $tenant) {
            $io->section($tenant->getName());
            $result = $this->onboarding->connectGoogle($tenant);
            OnboardingOutput::result($io, $result);
            $failed = $failed || SearchConsoleStatus::Failed === $tenant->getSearchConsoleStatus();
        }

        if ([] === $tenants) {
            $io->success('All clients are connected.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}

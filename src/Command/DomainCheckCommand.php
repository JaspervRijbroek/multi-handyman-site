<?php

declare(strict_types=1);

namespace App\Command;

use App\Hosting\CustomDomains;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:domains:check',
    description: 'Move client sites to their own domain once its DNS points to this server',
)]
final class DomainCheckCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly CustomDomains $customDomains,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ([] === $this->customDomains->serverAddresses()) {
            $io->warning('Could not look up the address of PLATFORM_HOST, so the domains cannot be checked. Trying again next time.');

            return Command::SUCCESS;
        }

        $moved = 0;
        foreach ($this->tenants->findBy(['active' => true]) as $tenant) {
            if ($live = $this->customDomains->updatePrimary($tenant)) {
                $io->writeln(\sprintf('%s now runs on %s', $tenant->getName(), $live->getHostname()));
                ++$moved;
            }
        }
        $this->entityManager->flush();

        $io->success(0 === $moved ? 'No sites to move.' : \sprintf('%d site(s) moved to their own domain.', $moved));

        return Command::SUCCESS;
    }
}

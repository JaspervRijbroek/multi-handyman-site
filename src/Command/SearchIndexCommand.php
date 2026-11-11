<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TenantRepository;
use App\Search\TownIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

#[AsCommand(
    name: 'app:search:index',
    description: 'Rebuild the OpenSearch index of towns, so the search understands towns typed with a typo',
)]
final class SearchIndexCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly TownIndex $index,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            app:schedule:run runs this hourly, so towns of new clients are picked up.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->index->isEnabled()) {
            $io->note('OPENSEARCH_URL is not set: nothing to index.');

            return Command::SUCCESS;
        }

        $towns = $this->tenants->findListedTowns();

        try {
            $this->index->rebuild($towns);
        } catch (HttpExceptionInterface $e) {
            $io->error('OpenSearch could not be reached: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d town(s) indexed.', \count($towns)));

        return Command::SUCCESS;
    }
}

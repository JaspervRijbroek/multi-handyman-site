<?php

declare(strict_types=1);

namespace App\Command;

use App\Turnstile\TurnstileException;
use App\Turnstile\TurnstileWidgetSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:turnstile:sync',
    description: 'Put every client domain (and PLATFORM_HOST) on a Cloudflare Turnstile widget',
)]
final class TurnstileSyncCommand extends Command
{
    public function __construct(
        private readonly TurnstileWidgetSync $sync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            Domains are synced automatically when they are saved in the admin. Run this
            daily from cron as well, to catch up if Cloudflare could not be reached:
              <info>15 9 * * * cd /var/www/multi-handyman-site && php bin/console app:turnstile:sync --no-interaction</info>
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->sync->isEnabled()) {
            $io->note('CLOUDFLARE_ACCOUNT_ID / CLOUDFLARE_API_TOKEN are not set: nothing to sync.');

            return Command::SUCCESS;
        }

        try {
            $stats = $this->sync->sync();
        } catch (TurnstileException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%d hostname(s) on %d widget(s): %d created, %d updated, %d deleted.',
            $stats['hostnames'],
            $stats['widgets'],
            $stats['created'],
            $stats['updated'],
            $stats['deleted'],
        ));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Billing\BillingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:billing:run',
    description: 'Daily billing: charge clients (monthly or yearly), announce yearly payments, send payment reminders, take unpaid sites offline',
)]
final class BillingRunCommand extends Command
{
    public function __construct(
        private readonly BillingService $billing,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Pretend today is this date (YYYY-MM-DD), for testing with a Mollie test key')
            ->setHelp(<<<'HELP'
                Runs daily at 09:00 through app:schedule:run.

                Safe to run more than once a day: a client is only charged when no charge covers
                today yet (monthly: once a month; yearly: once a year), and every email is sent once.
                Clients on the yearly plan get an email 2 weeks before their yearly payment.

                Charges and payment checks are queued and handled one client at a time by
                the queue worker (messenger:consume billing), which app:schedule:run starts.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $now = new \DateTimeImmutable();
        if ($date = $input->getOption('date')) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: throw new \InvalidArgumentException('Use --date=YYYY-MM-DD');
            $now = $day->setTime((int) $now->format('H'), (int) $now->format('i'));
        }

        $synced = $this->billing->syncPendingCharges();
        $io->writeln(\sprintf('Queued a Mollie check for %d open automatic payment(s).', $synced));

        $tenants = $this->billing->chargeDueClients($now);
        $io->writeln(\sprintf('%d client(s) queued to be charged.', \count($tenants)));
        foreach ($tenants as $tenant) {
            $io->writeln('  '.$tenant->getName());
        }

        $notices = $this->billing->sendRenewalNotices($now);
        $io->writeln(\sprintf('%d "yearly payment coming up" email(s) sent.', $notices));

        $overdue = $this->billing->processOverdue($now);
        $io->writeln(\sprintf('%d payment reminder(s) sent.', $overdue['reminders']));
        foreach ($overdue['suspended'] as $tenant) {
            $io->warning(\sprintf('%s has been taken offline for non-payment.', $tenant->getName()));
        }

        return Command::SUCCESS;
    }
}

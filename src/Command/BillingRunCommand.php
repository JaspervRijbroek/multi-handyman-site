<?php

declare(strict_types=1);

namespace App\Command;

use App\Billing\BillingService;
use App\Billing\WorkdayCalendar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:billing:run',
    description: 'Daily billing: charge clients on the first workday of the month, send payment reminders, take unpaid sites offline',
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

                Safe to run more than once a day: every client is charged at most once a month
                and every reminder is sent once.
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
        $io->writeln(\sprintf('Checked %d open automatic payment(s) with Mollie.', $synced));

        $charges = $this->billing->chargeDueClients($now);
        $io->writeln(\sprintf(
            '%d client(s) charged for %s (first workday: %s).',
            \count($charges),
            $now->format('F Y'),
            WorkdayCalendar::firstWorkdayOfMonth($now)->format('D j M'),
        ));
        foreach ($charges as $charge) {
            $io->writeln(\sprintf('  %s: %s', $charge->getTenant()->getName(), $charge->getStatus()));
        }

        $overdue = $this->billing->processOverdue($now);
        $io->writeln(\sprintf('%d payment reminder(s) sent.', $overdue['reminders']));
        foreach ($overdue['suspended'] as $tenant) {
            $io->warning(\sprintf('%s has been taken offline for non-payment.', $tenant->getName()));
        }

        return Command::SUCCESS;
    }
}

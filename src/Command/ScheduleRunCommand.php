<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:schedule:run',
    description: 'Run the scheduled tasks (billing, Google, Turnstile, search index) that are due this minute',
)]
final class ScheduleRunCommand extends Command
{
    /** Times are in Spanish time, whatever the server's timezone */
    private const string TIMEZONE = 'Europe/Madrid';

    /**
     * When each task runs: "09:00" is daily at 09:00, ":17" is hourly at 17 past.
     * Every task is safe to run again, so a missed run is caught up by the next one.
     */
    private const array TASKS = [
        ['09:00', 'app:billing:run'],
        ['09:15', 'app:turnstile:sync'],
        [':17', 'app:google:connect', '--all'],
        [':23', 'app:search:index'],
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('list', null, InputOption::VALUE_NONE, 'Only show the schedule')
            ->setHelp(<<<'HELP'
                The only cron job the app needs. Run it every minute:
                  <info>* * * * * cd /var/www/multi-handyman-site/current && php bin/console app:schedule:run >> var/log/schedule.log 2>&1</info>

                Each due task runs as its own process, one after the other, so a failing
                task doesn't stop the others. Add or change tasks in ScheduleRunCommand::TASKS.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            $io->table(['When', 'Command'], array_map(
                static fn (array $task) => [self::describe($task[0]), implode(' ', \array_slice($task, 1))],
                self::TASKS,
            ));

            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $failed = 0;

        foreach (self::TASKS as $task) {
            $at = array_shift($task);
            if (!self::isDue($at, $now)) {
                continue;
            }

            $output->writeln(\sprintf('[%s] %s', $now->format('Y-m-d H:i'), implode(' ', $task)));
            $process = new Process([\PHP_BINARY, 'bin/console', ...$task, '--no-interaction'], $this->projectDir, timeout: null);
            $process->run(static fn (string $type, string $buffer) => $output->write($buffer));

            if (!$process->isSuccessful()) {
                ++$failed;
                $io->error(\sprintf('%s failed (exit code %d)', $task[0], $process->getExitCode()));
            }
        }

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    private static function isDue(string $at, \DateTimeImmutable $now): bool
    {
        return str_starts_with($at, ':') ? $at === $now->format(':i') : $at === $now->format('H:i');
    }

    private static function describe(string $at): string
    {
        return str_starts_with($at, ':') ? 'hourly at '.$at : 'daily at '.$at;
    }
}

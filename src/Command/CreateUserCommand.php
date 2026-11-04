<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Create an admin login (super admin, or a client login with --tenant)',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenants,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Login email address')
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Slug of the client this login belongs to')
            ->addOption('super-admin', null, InputOption::VALUE_NONE, 'Give access to all clients and platform settings')
            ->setHelp(<<<'HELP'
                Examples:
                  <info>php bin/console app:user:create you@yourdomain.com --super-admin</info>
                  <info>php bin/console app:user:create jan@example.com --tenant=jans-klusbedrijf</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = (new User())->setEmail($input->getArgument('email'));

        if ($input->getOption('super-admin')) {
            $user->setRoles([User::ROLE_SUPER_ADMIN]);
        }

        if ($slug = $input->getOption('tenant')) {
            $tenant = $this->tenants->findOneBy(['slug' => $slug]);
            if (null === $tenant) {
                $io->error(\sprintf('No client with slug "%s".', $slug));

                return Command::FAILURE;
            }
            $user->setTenant($tenant);
        }

        $password = $io->askHidden('Password (min. 10 characters)', static function (?string $value): string {
            if (null === $value || mb_strlen($value) < 10) {
                throw new \RuntimeException('Use at least 10 characters.');
            }

            return $value;
        });
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error($violation->getPropertyPath().': '.$violation->getMessage());
            }

            return Command::FAILURE;
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('Login %s created.', $user->getEmail()));

        return Command::SUCCESS;
    }
}

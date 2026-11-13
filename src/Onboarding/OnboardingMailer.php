<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Entity\Signup;
use App\Entity\Tenant;
use App\Entity\User;
use App\Hosting\CustomDomains;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class OnboardingMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private UserRepository $users,
        private LoggerInterface $logger,
        private CustomDomains $customDomains,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
    ) {
    }

    /** Tells the client their website is ready and how to log in. */
    public function sendWelcome(User $user, Tenant $tenant, ?string $setupToken, OnboardingResult $result): void
    {
        $setupUrl = $setupToken
            ? $this->urlGenerator->generate('app_password_setup', ['token' => $setupToken], UrlGeneratorInterface::ABSOLUTE_URL)
            : null;
        $domain = $tenant->getPrimaryDomain();
        // Their own domain, while it does not point to the server yet
        $ownDomain = array_values(array_filter(
            $this->customDomains->ownDomains($tenant),
            static fn ($d) => $d !== $domain && !str_starts_with($d->getHostname(), 'www.'),
        ))[0] ?? null;

        $email = (new TemplatedEmail())
            ->from($this->from)
            ->to(new Address($user->getEmail(), $tenant->getLegalName() ?? ''))
            ->subject(\sprintf('Your website for %s is ready', $tenant->getName()))
            ->textTemplate('email/welcome.txt.twig')
            ->context([
                'tenant' => $tenant,
                'login_email' => $user->getEmail(),
                'login_url' => $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'setup_url' => $setupUrl,
                'site_url' => $domain ? 'https://'.$domain->getHostname() : null,
                'own_domain' => $ownDomain?->getHostname(),
                'dns_records' => $ownDomain ? $this->customDomains->dnsRecords() : [],
            ]);

        try {
            $this->mailer->send($email);
            $result->add(OnboardingResult::OK, 'Welcome email', 'Sent to '.$user->getEmail().($setupUrl ? ', with a link to set a password' : ''));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Could not send welcome email: {message}', ['message' => $e->getMessage()]);
            // Only admins see this result, so they can pass the link on by hand
            $result->add(OnboardingResult::WARNING, 'Welcome email', 'Could not be sent ('.$e->getMessage().').'.($setupUrl ? ' Send the client this password link yourself (valid 7 days): '.$setupUrl : ''));
        }
    }

    /** Lets the super admins know a new sign-up is waiting for approval. */
    public function notifyAdmins(Signup $signup): void
    {
        $recipients = array_map(static fn (User $u) => $u->getEmail(), $this->users->findSuperAdmins());
        if ([] === $recipients) {
            return;
        }

        $email = (new TemplatedEmail())
            ->from($this->from)
            ->to(...$recipients)
            ->replyTo($signup->getEmail())
            ->subject(\sprintf('New sign-up: %s (%s)', $signup->getBusinessName(), $signup->getCity()))
            ->textTemplate('email/signup_admin.txt.twig')
            ->context([
                'signup' => $signup,
                'admin_url' => $this->urlGenerator->generate('admin', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // The sign-up is in the admin either way
            $this->logger->error('Could not send sign-up notification: {message}', ['message' => $e->getMessage()]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Invitation;

use App\Billing\BillingMailer;
use App\Entity\Invitation;
use App\Entity\Tenant;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Clients invite other businesses (Invitation::PER_CLIENT each, used or not).
 *
 * 1. send(): the invitee gets an email with a sign-up link that works once.
 * 2. On approval the new client gets a free month (ClientOnboarding), so their
 *    first payment covers their 2nd month too.
 * 3. Once the new client has set up payment, the inviter gets a free month as
 *    well (rewardInviter(), called by BillingService).
 *
 * A free month: the next payment covers one extra month at the same price (Tenant::$freeMonths).
 */
final readonly class Invitations
{
    public function __construct(
        private InvitationRepository $invitations,
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private BillingMailer $billingMailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
        #[Autowire(env: 'BILLING_BRAND')]
        private string $brand,
    ) {
    }

    public function left(Tenant $inviter): int
    {
        return max(0, Invitation::PER_CLIENT - $this->invitations->countFor($inviter));
    }

    /**
     * $locale: the language of the email and the sign-up form it links to.
     *
     * @throws \LogicException              when the client has no invitations left
     * @throws TransportExceptionInterface when the email could not be sent; the invitation is not kept then
     */
    public function send(Tenant $inviter, string $name, string $email, string $locale): Invitation
    {
        if (0 === $this->left($inviter)) {
            throw new \LogicException(\sprintf('You have already sent your %d invitations.', Invitation::PER_CLIENT));
        }

        $invitation = new Invitation($inviter, $name, $email);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $message = (new TemplatedEmail())
            ->from(new Address($this->from, $this->brand))
            ->to(new Address($invitation->getEmail(), $invitation->getName()))
            ->locale($locale)
            ->subject($this->translator->trans('invitation.subject', ['%inviter%' => $inviter->getName(), '%brand%' => $this->brand], 'email', $locale))
            ->htmlTemplate('email/invitation.html.twig')
            ->context([
                'invitation' => $invitation,
                'inviter' => $inviter,
                // The sign-up form in the same language as the email
                'register_url' => $this->urlGenerator->generate('app_register', ['_locale' => $locale, 'invite' => $invitation->getToken()], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        if ($inviter->getEmail()) {
            $message->replyTo($inviter->getEmail());
        }

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            // Not sent, so it should not use up one of the invitations
            $this->entityManager->remove($invitation);
            $this->entityManager->flush();

            throw $e;
        }

        return $invitation;
    }

    /**
     * Gives the inviter their free month, once: called when $invitee has set up payment.
     * Only reads and writes Tenant: the invitee's own tenant may be active, which hides the inviter's Invitations.
     */
    public function rewardInviter(Tenant $invitee): void
    {
        $inviter = $invitee->getInvitedBy();
        if (null === $inviter || null !== $invitee->getInviterRewardedAt() || !$inviter->isActive()) {
            return;
        }

        $inviter->addFreeMonth();
        $invitee->setInviterRewardedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->billingMailer->sendInviteReward($inviter, $invitee);
    }
}

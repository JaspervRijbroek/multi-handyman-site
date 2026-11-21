<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Signup;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\SignupType;
use App\Onboarding\OnboardingMailer;
use App\Repository\InvitationRepository;
use App\Repository\SignupRepository;
use App\Turnstile\Turnstile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public sign-up on PLATFORM_HOST (TenantRequestListener returns 404 for it on
 * client sites). Only stores a Signup: a super admin approves
 * it in the admin (or with app:client:approve), which creates the actual client.
 */
final class RegistrationController extends AbstractController
{
    /** How many sign-ups the shared founding link (?founding=FOUNDING_INVITE_CODE) accepts */
    public const int FOUNDING_SPOTS = 40;

    /** Old and short link: the sign-up form in the visitor's language */
    #[Route('/register', name: 'app_register_redirect', methods: ['GET'])]
    public function redirectToLocale(Request $request): Response
    {
        // Keeps ?invite= (the link in invitation emails), ?founding= and ?plan=
        return $this->redirectToRoute('app_register', [...$request->query->all(), '_locale' => PlatformController::preferredLocale($request)]);
    }

    #[Route('/{_locale}/register', name: 'app_register', requirements: ['_locale' => PlatformController::LOCALE_REQUIREMENT], methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        TranslatorInterface $translator,
        EntityManagerInterface $entityManager,
        PasswordHasherFactoryInterface $hasherFactory,
        OnboardingMailer $mailer,
        #[Autowire(service: 'limiter.signup')] RateLimiterFactoryInterface $signupLimiter,
        Turnstile $turnstile,
        InvitationRepository $invitations,
        SignupRepository $signups,
        #[Autowire(env: 'FOUNDING_INVITE_CODE')] string $foundingCode,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin');
        }

        // The shared founding link: once all spots are taken it leads to the page for professionals
        $founding = '' !== $foundingCode && hash_equals($foundingCode, $request->query->getString('founding'));
        if ($founding && $signups->countFounding() >= self::FOUNDING_SPOTS) {
            return $this->redirectToRoute('platform_pros');
        }

        // A new Signup, so its defaults (English and Spanish) are ticked; ?plan=yearly comes from the pricing toggle
        $signup = (new Signup())->setBillingPlan(Tenant::PLAN_YEARLY === $request->query->get('plan') ? Tenant::PLAN_YEARLY : Tenant::PLAN_MONTHLY);
        $form = $this->createForm(SignupType::class, $signup);
        // ?invite= stays in the URL the form posts to
        $inviteToken = $request->query->getString('invite');
        $invitation = $invitations->findOpenByToken($inviteToken);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // Counted for every submission, so the form cannot be used to probe which emails have an account
            if (!$signupLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError($translator->trans('signup.too_many', domain: 'platform')));
            } elseif ($form->isValid()) {
                // Bots fill in the hidden field: pretend everything went fine
                if ($form->get(SignupType::HONEYPOT)->getData()) {
                    return $this->redirectToRoute('app_register_thanks');
                }

                if (!$turnstile->verify($request, 'signup')) {
                    // Shown above the form, which keeps what was typed (passwords excepted)
                    $form->addError(new FormError($translator->trans('signup.turnstile', domain: 'platform')));
                } else {
                    /** @var Signup $signup */
                    $signup = $form->getData();
                    // Hashed the same way as logins, so the hash can be used as-is on approval
                    $signup->setPasswordHash($hasherFactory->getPasswordHasher(User::class)->hash($form->get('plainPassword')->getData()));
                    // The consent checkbox is required, so ticked here: keep when, as proof
                    $signup->acceptTerms();
                    $signup->setInvitation($invitation);
                    $signup->setFoundingMember($founding);

                    $entityManager->persist($signup);
                    $entityManager->flush();
                    $mailer->notifyAdmins($signup);

                    return $this->redirectToRoute('app_register_thanks');
                }
            }
        }

        return $this->render('platform/register.html.twig', [
            'form' => $form,
            'invitation' => $invitation,
            'founding' => $founding,
            'invite_used' => '' !== $inviteToken && null === $invitation,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{_locale}/register/thanks', name: 'app_register_thanks', requirements: ['_locale' => PlatformController::LOCALE_REQUIREMENT], methods: ['GET'])]
    public function thanks(): Response
    {
        return $this->render('platform/register_thanks.html.twig');
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Signup;
use App\Entity\User;
use App\Form\SignupType;
use App\Onboarding\OnboardingMailer;
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

/**
 * Public sign-up on PLATFORM_HOST (TenantRequestListener returns 404 for it on
 * client sites). Only stores a Signup: a super admin approves
 * it in the admin (or with app:client:approve), which creates the actual client.
 */
final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        PasswordHasherFactoryInterface $hasherFactory,
        OnboardingMailer $mailer,
        #[Autowire(service: 'limiter.signup')] RateLimiterFactoryInterface $signupLimiter,
        Turnstile $turnstile,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin');
        }

        // A new Signup, so its defaults (English and Spanish) are ticked
        $form = $this->createForm(SignupType::class, new Signup());
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // Counted for every submission, so the form cannot be used to probe which emails have an account
            if (!$signupLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError('Too many attempts from your connection. Please try again later, or email us.'));
            } elseif ($form->isValid()) {
                // Bots fill in the hidden field: pretend everything went fine
                if ($form->get(SignupType::HONEYPOT)->getData()) {
                    return $this->redirectToRoute('app_register_thanks');
                }

                if (!$turnstile->verify($request, 'signup')) {
                    // Shown above the form, which keeps what was typed (passwords excepted)
                    $form->addError(new FormError('Please confirm you are not a robot and send the form again.'));
                } else {
                    /** @var Signup $signup */
                    $signup = $form->getData();
                    // Hashed the same way as logins, so the hash can be used as-is on approval
                    $signup->setPasswordHash($hasherFactory->getPasswordHasher(User::class)->hash($form->get('plainPassword')->getData()));

                    $entityManager->persist($signup);
                    $entityManager->flush();
                    $mailer->notifyAdmins($signup);

                    return $this->redirectToRoute('app_register_thanks');
                }
            }
        }

        return $this->render('platform/register.html.twig', [
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/register/thanks', name: 'app_register_thanks', methods: ['GET'])]
    public function thanks(): Response
    {
        return $this->render('platform/register_thanks.html.twig');
    }
}

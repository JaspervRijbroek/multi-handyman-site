<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One-time "choose your password" link from the welcome email.
 * Only on PLATFORM_HOST: TenantRequestListener returns 404 for it on client sites.
 */
final class PasswordSetupController extends AbstractController
{
    #[Route('/set-password/{token}', name: 'app_password_setup', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function setup(
        string $token,
        Request $request,
        UserRepository $users,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $users->findOneByPasswordSetupToken($token);
        if (null === $user || $user->isPasswordSetupTokenExpired()) {
            return $this->render('platform/password_setup.html.twig', ['form' => null], new Response(status: 410));
        }

        $form = $this->createFormBuilder()
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => ['label' => 'New password', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Repeat password', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'The passwords do not match.',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 10, max: 4096, minMessage: 'Use at least {{ limit }} characters.')],
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($passwordHasher->hashPassword($user, $form->get('password')->getData()));
            $user->clearPasswordSetupToken();
            $entityManager->flush();

            $this->addFlash('success', 'Your password is set. You can log in now.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('platform/password_setup.html.twig', [
            'form' => $form,
            'email' => $user->getEmail(),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}

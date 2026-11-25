<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Invitation;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\SignupType;
use App\Invitation\Invitations;
use App\Repository\InvitationRepository;
use App\Tenant\TenantContext;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The client's "Invite a business" page: send up to Invitation::PER_CLIENT invitations
 * and see what became of them. See App\Invitation\Invitations.
 */
#[IsGranted(User::ROLE_CLIENT)]
#[AdminRoute('/invitations', name: 'invitations')]
final class InvitationAdminController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InvitationRepository $repository,
        private readonly Invitations $invitations,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[AdminRoute('/', name: 'index')]
    public function index(): Response
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            // Super admins see invitations per sign-up and per client
            return $this->redirectToRoute('admin_signup_index');
        }

        return $this->render('admin/invitations/index.html.twig', [
            'tenant' => $tenant,
            // The tenant filter limits this to the client's own invitations
            'invitations' => $this->repository->findFor($tenant),
            'left' => $this->invitations->left($tenant),
            'total' => Invitation::PER_CLIENT,
            'languages' => SignupType::LANGUAGES,
        ]);
    }

    #[AdminRoute('/send', name: 'send', options: ['methods' => ['POST']])]
    public function send(Request $request): Response
    {
        $tenant = $this->clientTenant($request);
        $name = trim($request->request->getString('name'));
        $email = trim($request->request->getString('email'));
        $locale = $request->request->getString('locale');
        if (!\in_array($locale, Tenant::SUPPORTED_LOCALES, true)) {
            $locale = $tenant->getEmailLocale();
        }

        // Same rules as the entity, checked before anything is saved or sent
        $problems = $this->validator->validate(new Invitation($tenant, $name, $email));
        if (\count($problems) > 0) {
            foreach ($problems as $problem) {
                $this->addFlash('danger', ucfirst($problem->getPropertyPath()).': '.$problem->getMessage());
            }

            return $this->redirectToRoute('admin_invitations_index');
        }

        try {
            $this->invitations->send($tenant, $name, $email, $locale);
            $this->addFlash('success', \sprintf('Your invitation is on its way to %s.', $email));
        } catch (\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());
        } catch (TransportExceptionInterface) {
            $this->addFlash('danger', 'The invitation could not be sent. Please try again later; it did not count.');
        }

        return $this->redirectToRoute('admin_invitations_index');
    }

    private function clientTenant(Request $request): Tenant
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw $this->createAccessDeniedException('Only clients have this page.');
        }
        if (!$this->isCsrfTokenValid('invitations-'.$tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form, please try again.');
        }

        return $tenant;
    }
}

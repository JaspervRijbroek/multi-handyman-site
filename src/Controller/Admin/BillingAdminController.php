<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\ChargeRepository;
use App\Tenant\TenantContext;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The client's "Billing" page in the admin: payment method, open payments and history.
 * The buttons post to the payment pages in App\Controller\BillingController.
 */
#[IsGranted(User::ROLE_CLIENT)]
#[AdminRoute('/billing', name: 'billing')]
final class BillingAdminController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ChargeRepository $charges,
    ) {
    }

    #[AdminRoute('/', name: 'index')]
    public function index(): Response
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            // Super admins manage billing per client
            return $this->redirectToRoute('admin_charge_index');
        }

        $outstanding = $this->charges->findOutstanding($tenant);

        return $this->render('admin/billing/index.html.twig', [
            'tenant' => $tenant,
            'outstanding' => $outstanding,
            'total' => array_sum(array_map(static fn ($c) => $c->getTotalAmount(), $outstanding)),
            // The tenant filter limits this to the client's own charges
            'history' => $this->charges->findBy(['tenant' => $tenant], ['period' => 'DESC'], 24),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Domain;
use App\Entity\Tenant;
use App\Entity\User;
use App\Hosting\CustomDomains;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The client's "Your domain" page: add their own domain, see the DNS records to set
 * and whether they work yet. The domain goes live by itself once the DNS is right.
 */
#[IsGranted(User::ROLE_CLIENT)]
#[AdminRoute('/your-domain', name: 'own_domain')]
final class DomainAdminController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CustomDomains $customDomains,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[AdminRoute('/', name: 'index')]
    public function index(): Response
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            // Super admins manage all hostnames on the Domains screen
            return $this->redirectToRoute('admin_domain_index');
        }

        $this->goLiveIfReady($tenant);
        $own = $this->customDomains->ownDomains($tenant);

        return $this->render('admin/domain/index.html.twig', [
            'tenant' => $tenant,
            'primary' => $tenant->getPrimaryDomain(),
            'own_domains' => array_map(fn (Domain $d) => [
                'hostname' => $d->getHostname(),
                'primary' => $d === $tenant->getPrimaryDomain(),
                'points_here' => $this->customDomains->pointsHere($d->getHostname()),
                'found' => $this->customDomains->lookup($d->getHostname()),
            ], $own),
            'dns_records' => $this->customDomains->dnsRecords(),
            // Without a platform subdomain the own domain is the only address, so it cannot be removed
            'can_remove' => \count($own) < $tenant->getDomains()->count(),
        ]);
    }

    #[AdminRoute('/add', name: 'add', options: ['methods' => ['POST']])]
    public function add(Request $request): Response
    {
        $tenant = $this->clientTenant($request);
        if ([] !== $this->customDomains->ownDomains($tenant)) {
            $this->addFlash('warning', 'You already have a domain. Remove it first to use another one.');

            return $this->redirectToRoute('admin_own_domain_index');
        }

        $hostname = Domain::clean($request->request->getString('hostname'));
        $problems = $this->customDomains->problems($hostname);
        if ([] !== $problems) {
            foreach ($problems as $problem) {
                $this->addFlash('danger', $problem);
            }

            return $this->redirectToRoute('admin_own_domain_index');
        }

        $this->customDomains->addOwnDomain($tenant, $hostname);
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('%s is added. Now set the DNS records below where you bought the domain.', $hostname));

        return $this->redirectToRoute('admin_own_domain_index');
    }

    #[AdminRoute('/remove', name: 'remove', options: ['methods' => ['POST']])]
    public function remove(Request $request): Response
    {
        $tenant = $this->clientTenant($request);
        $own = $this->customDomains->ownDomains($tenant);

        if ([] === $own || \count($own) === $tenant->getDomains()->count()) {
            $this->addFlash('warning', 'Your website needs at least one address. Contact us to change your domain.');

            return $this->redirectToRoute('admin_own_domain_index');
        }

        $this->customDomains->removeOwnDomains($tenant);
        $this->entityManager->flush();
        $this->addFlash('success', 'Your domain is removed. Your website is back on '.$tenant->getPrimaryDomain()?->getHostname().'.');

        return $this->redirectToRoute('admin_own_domain_index');
    }

    /** Same check as the hourly app:domains:check, so the client sees the result right away */
    private function goLiveIfReady(Tenant $tenant): void
    {
        if ($live = $this->customDomains->updatePrimary($tenant)) {
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Your website now runs on https://%s.', $live->getHostname()));
        }
    }

    private function clientTenant(Request $request): Tenant
    {
        $tenant = $this->tenantContext->get();
        if (null === $tenant) {
            throw $this->createAccessDeniedException('Only clients have this page.');
        }
        if (!$this->isCsrfTokenValid('domain-'.$tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form, please try again.');
        }

        return $tenant;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Hosting\CustomDomains;
use App\Repository\ChargeRepository;
use App\Repository\EnquiryRepository;
use App\Repository\ProjectRepository;
use App\Repository\ReviewRepository;
use App\Repository\ServiceRepository;
use App\Repository\SignupRepository;
use App\Repository\TenantRepository;
use App\Tenant\TenantContext;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EnquiryRepository $enquiries,
        private readonly ProjectRepository $projects,
        private readonly ReviewRepository $reviews,
        private readonly ServiceRepository $services,
        private readonly TenantRepository $tenants,
        private readonly SignupRepository $signups,
        private readonly ChargeRepository $charges,
        private readonly CustomDomains $customDomains,
    ) {
    }

    public function index(): Response
    {
        // All counts are automatically limited to the client's own data by the tenant filter
        $stats = [
            ['label' => 'New enquiries', 'value' => $this->enquiries->count(['handled' => false])],
            ['label' => 'Reviews to check', 'value' => $this->reviews->countPending()],
            ['label' => 'Finished jobs on the website', 'value' => $this->projects->count(['published' => true])],
            ['label' => 'Services', 'value' => $this->services->count([])],
        ];

        if (!$this->tenantContext->has()) {
            $stats[] = ['label' => 'Active clients', 'value' => $this->tenants->count(['active' => true])];
            $stats[] = ['label' => 'Open payments', 'value' => \count($this->charges->findAllOutstanding())];
        }

        $tenant = $this->tenantContext->get();
        $primaryDomain = $tenant?->getPrimaryDomain();

        // Nudge clients to fill in the details that help them get found
        $missing = [];
        if ($tenant) {
            if (!$tenant->getLogoName()) {
                $missing[] = 'your logo';
            }
            if (!$tenant->hasAddress()) {
                $missing[] = 'your address (for the map)';
            }
            if (!$tenant->getTrades()) {
                $missing[] = 'your trades (to be found in the search)';
            }
            if (!$tenant->getServiceArea()) {
                $missing[] = 'the towns you work in';
            }
            if (!$tenant->getPhone() && !$tenant->getWhatsapp()) {
                $missing[] = 'a phone or WhatsApp number';
            }
        }

        return $this->render('admin/dashboard.html.twig', [
            'tenant' => $tenant,
            'pending_signups' => $this->isGranted(User::ROLE_SUPER_ADMIN) ? $this->signups->countPending() : 0,
            'outstanding' => $tenant ? $this->charges->findOutstanding($tenant) : [],
            'missing_settings' => $missing,
            'stats' => $stats,
            'site_url' => $primaryDomain ? 'https://'.$primaryDomain->getHostname() : null,
            // Their own domain, added but not live yet (no DNS lookups here: the domain page does those)
            'pending_domain' => $tenant && $primaryDomain && $this->customDomains->isPlatformSubdomain($primaryDomain)
                ? ($this->customDomains->ownDomains($tenant)[0] ?? null)
                : null,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        $tenant = $this->tenantContext->get();
        $title = htmlspecialchars($tenant?->getName() ?? 'Platform admin', \ENT_QUOTES);

        // Clients see their own logo in the admin, so it feels like their own site
        if ($tenant?->getLogoName()) {
            $title = \sprintf(
                '<img src="/uploads/logos/%s" alt="" style="max-height: 32px; max-width: 120px; margin-right: 8px; vertical-align: middle"> %s',
                rawurlencode($tenant->getLogoName()),
                $title,
            );
        }

        return Dashboard::new()
            ->setTitle($title)
            ->setFaviconPath('favicon.svg');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');

        $tenant = $this->tenantContext->get();

        yield MenuItem::linkTo(ProjectCrudController::class, 'Finished jobs', 'fa fa-images');
        yield MenuItem::linkTo(ServiceCrudController::class, 'Services', 'fa fa-wrench');
        yield MenuItem::linkTo(EnquiryCrudController::class, 'Enquiries', 'fa fa-envelope');
        yield MenuItem::linkTo(ReviewCrudController::class, 'Reviews', 'fa fa-star')
            ->setBadge($this->reviews->countPending() ?: null, 'warning');

        if ($tenant && $domain = $tenant->getPrimaryDomain()) {
            yield MenuItem::linkToUrl('View website', 'fa fa-external-link-alt', 'https://'.$domain->getHostname())
                ->setLinkTarget('_blank');
        }

        if ($tenant) {
            yield MenuItem::section('Your business');
            yield MenuItem::linkTo(TenantCrudController::class, 'Settings', 'fa fa-gear')
                ->setAction(Action::EDIT)
                ->setEntityId($tenant->getId());
            yield MenuItem::linkToRoute('Your domain', 'fa fa-globe', 'admin_own_domain_index');
            if (!$tenant->isBillingExempt()) {
                yield MenuItem::linkToRoute('Billing', 'fa fa-credit-card', 'admin_billing_index');
            }
        }

        if ($this->isGranted(User::ROLE_SUPER_ADMIN)) {
            yield MenuItem::section('Platform');
            $pending = $this->signups->countPending();
            yield MenuItem::linkTo(SignupCrudController::class, 'Sign-ups', 'fa fa-user-plus')
                ->setBadge($pending ?: null, 'warning');
            yield MenuItem::linkTo(TenantCrudController::class, 'Clients', 'fa fa-building');
            yield MenuItem::linkTo(ChargeCrudController::class, 'Payments', 'fa fa-credit-card');
            yield MenuItem::linkTo(DomainCrudController::class, 'Domains', 'fa fa-globe');
            yield MenuItem::linkTo(UserCrudController::class, 'Logins', 'fa fa-users');
        }
    }
}

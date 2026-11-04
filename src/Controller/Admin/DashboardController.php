<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\EnquiryRepository;
use App\Repository\ProjectRepository;
use App\Repository\ServiceRepository;
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
        private readonly ServiceRepository $services,
        private readonly TenantRepository $tenants,
    ) {
    }

    public function index(): Response
    {
        // All counts are automatically limited to the client's own data by the tenant filter
        $stats = [
            ['label' => 'New enquiries', 'value' => $this->enquiries->count(['handled' => false])],
            ['label' => 'Finished jobs on the website', 'value' => $this->projects->count(['published' => true])],
            ['label' => 'Services', 'value' => $this->services->count([])],
        ];

        if (!$this->tenantContext->has()) {
            $stats[] = ['label' => 'Active clients', 'value' => $this->tenants->count(['active' => true])];
        }

        $tenant = $this->tenantContext->get();
        $primaryDomain = $tenant?->getPrimaryDomain();

        return $this->render('admin/dashboard.html.twig', [
            'tenant' => $tenant,
            'stats' => $stats,
            'site_url' => $primaryDomain ? 'https://'.$primaryDomain->getHostname() : null,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle($this->tenantContext->get()?->getName() ?? 'Platform admin')
            ->setFaviconPath('favicon.svg');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');

        if ($tenant = $this->tenantContext->get()) {
            yield MenuItem::linkTo(TenantCrudController::class, 'Business details', 'fa fa-store')
                ->setAction(Action::EDIT)
                ->setEntityId($tenant->getId());
        }

        yield MenuItem::linkTo(ProjectCrudController::class, 'Finished jobs', 'fa fa-images');
        yield MenuItem::linkTo(ServiceCrudController::class, 'Services', 'fa fa-wrench');
        yield MenuItem::linkTo(EnquiryCrudController::class, 'Enquiries', 'fa fa-envelope');

        if ($tenant && $domain = $tenant->getPrimaryDomain()) {
            yield MenuItem::linkToUrl('View website', 'fa fa-external-link-alt', 'https://'.$domain->getHostname())
                ->setLinkTarget('_blank');
        }

        if ($this->isGranted(User::ROLE_SUPER_ADMIN)) {
            yield MenuItem::section('Platform');
            yield MenuItem::linkTo(TenantCrudController::class, 'Clients', 'fa fa-building');
            yield MenuItem::linkTo(DomainCrudController::class, 'Domains', 'fa fa-globe');
            yield MenuItem::linkTo(UserCrudController::class, 'Logins', 'fa fa-users');
        }
    }
}

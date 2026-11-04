<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Repository\DomainRepository;
use App\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Decides, per request, which tenant is active.
 *
 * PLATFORM_HOST          → admin, login and the platform landing page (no tenant from the URL)
 * any known client host  → that client's website
 * anything else          → 404
 */
final readonly class TenantRequestListener
{
    private string $platformHost;

    public function __construct(
        private TenantContext $tenantContext,
        private DomainRepository $domains,
        private Security $security,
        #[Autowire(env: 'PLATFORM_HOST')]
        string $platformHost,
    ) {
        $this->platformHost = strtolower($platformHost);
    }

    /** Runs after routing (32), before the firewall (8). */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function resolveSiteTenant(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $host = strtolower($request->getHost());
        $path = $request->getPathInfo();

        // Caddy's on-demand TLS check is called on localhost, see TlsController
        if ($host === $this->platformHost || str_starts_with($path, '/_tls/')) {
            return;
        }

        $domain = $this->domains->findOneByHostname($host);
        $tenant = $domain?->getTenant();

        if (null === $tenant || !$tenant->isActive()) {
            throw new NotFoundHttpException(\sprintf('No site is configured for "%s".', $host));
        }

        // The admin only lives on the platform host
        if (preg_match('#^/(admin|login|logout)(/|$)#', $path)) {
            throw new NotFoundHttpException();
        }

        // One canonical hostname per site (e.g. www.example.com → example.com)
        $primary = $tenant->getPrimaryDomain();
        if (null !== $primary && $primary !== $domain) {
            $event->setResponse(new RedirectResponse(
                $request->getScheme().'://'.$primary->getHostname().$request->getRequestUri(),
                301,
            ));

            return;
        }

        $this->tenantContext->set($tenant);
    }

    /** Runs right after the firewall (8), so the logged-in user is known. */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
    public function resolveAdminTenant(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->tenantContext->has()) {
            return;
        }

        if (strtolower($event->getRequest()->getHost()) !== $this->platformHost) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isSuperAdmin()) {
            return;
        }

        $tenant = $user->getTenant();
        if (null === $tenant || !$tenant->isActive()) {
            throw new NotFoundHttpException('This account is not linked to an active client.');
        }

        $this->tenantContext->set($tenant);
    }
}

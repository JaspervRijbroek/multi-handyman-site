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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Decides, per request, which tenant is active.
 *
 * PLATFORM_HOST          → admin, login, the pro search and the platform pages (no tenant from the URL)
 * any known client host  → that client's website ("temporarily offline" if suspended for non-payment)
 * anything else          → 404
 */
final readonly class TenantRequestListener
{
    private string $platformHost;

    public function __construct(
        private TenantContext $tenantContext,
        private DomainRepository $domains,
        private Security $security,
        private Environment $twig,
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

        // The admin, sign-up, password, payment and search pages only live on the platform host
        // (some of them behind a language, e.g. /es/search)
        if (preg_match('#^/([a-z]{2}/)?(admin|login|logout|register|set-password|billing|search|for-professionals)(/|$)#', $path)) {
            throw new NotFoundHttpException();
        }

        // Taken offline for non-payment. 503 tells search engines to come back later
        // instead of dropping the site from their index.
        if ($tenant->isSuspended()) {
            $event->setResponse(new Response(
                $this->twig->render('site/offline.html.twig', ['tenant' => $tenant]),
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Retry-After' => '86400', 'Cache-Control' => 'no-store'],
            ));

            return;
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

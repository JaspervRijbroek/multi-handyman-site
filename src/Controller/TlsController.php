<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DomainRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Production only: Caddy asks this endpoint before requesting a TLS certificate
 * for a hostname (on-demand TLS). Only hostnames in the domain table get one,
 * so nobody can make the server request certificates for random domains.
 * See deploy/Caddyfile.
 */
#[AsController]
final class TlsController
{
    #[Route('/_tls/ask', name: 'tls_ask', methods: ['GET'])]
    public function ask(
        Request $request,
        DomainRepository $domains,
        #[Autowire(env: 'PLATFORM_HOST')] string $platformHost,
    ): Response {
        if (!\in_array($request->getClientIp(), ['127.0.0.1', '::1'], true)) {
            throw new NotFoundHttpException();
        }

        $hostname = strtolower((string) $request->query->get('domain'));
        $known = $hostname === strtolower($platformHost) || null !== $domains->findOneByHostname($hostname);

        return new Response('', $known ? 200 : 404);
    }
}

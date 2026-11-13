<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mcp\McpServer;
use App\Repository\TenantRepository;
use App\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MCP endpoint for AI assistants (PLATFORM_HOST/mcp, "Streamable HTTP" transport).
 *
 * Each client creates their own token under Settings → AI assistant. The token
 * decides which client's data the assistant works on; without a valid token
 * nothing is allowed.
 */
#[AsController]
final class McpController
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly TenantContext $tenantContext,
        private readonly McpServer $server,
    ) {
    }

    #[Route('/mcp', name: 'mcp', methods: ['GET', 'POST', 'DELETE'])]
    public function __invoke(Request $request): Response
    {
        $header = (string) $request->headers->get('Authorization');
        $token = preg_match('/^Bearer\s+(\S+)$/i', $header, $match) ? $match[1] : '';
        $tenant = $this->tenants->findOneByMcpToken($token);

        if (null === $tenant) {
            return new JsonResponse(
                McpServer::error(null, -32001, 'Missing or invalid token. Create one in the admin under Settings → AI assistant.'),
                Response::HTTP_UNAUTHORIZED,
                ['WWW-Authenticate' => 'Bearer'],
            );
        }

        // Stateless server: no event stream (GET) and no sessions to end (DELETE)
        if (!$request->isMethod('POST')) {
            return new Response('', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST']);
        }

        $message = json_decode($request->getContent(), true);
        if (!\is_array($message) || array_is_list($message)) {
            return new JsonResponse(McpServer::error(null, -32700, 'Send one JSON-RPC message as a JSON object.'), Response::HTTP_BAD_REQUEST);
        }

        // From here on the tenant filter and guards limit everything to this client
        $this->tenantContext->set($tenant);

        $response = $this->server->handle($message);

        return null === $response
            ? new Response('', Response::HTTP_ACCEPTED)
            : new JsonResponse($response);
    }
}

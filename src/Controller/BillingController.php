<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\BillingService;
use App\Billing\MollieException;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ChargeRepository;
use App\Repository\TenantRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Payment pages on the platform host. The token in the URL is the client's secret
 * billing token, so the link in reminder emails works without logging in.
 * Client hosts never reach these routes (see TenantRequestListener).
 */
final class BillingController extends AbstractController
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly TenantRepository $tenants,
        private readonly ChargeRepository $charges,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/billing/{token}', name: 'billing_pay', requirements: ['token' => '[a-f0-9]{48}'], methods: ['GET'])]
    public function show(string $token): Response
    {
        $tenant = $this->tenantByToken($token);
        $outstanding = $this->charges->findOutstanding($tenant);

        $response = $this->render('billing/pay.html.twig', [
            'tenant' => $tenant,
            'outstanding' => $outstanding,
            'total' => array_sum(array_map(static fn ($c) => $c->getTotalAmount(), $outstanding)),
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    #[Route('/billing/{token}/pay', name: 'billing_pay_outstanding', requirements: ['token' => '[a-f0-9]{48}'], methods: ['POST'])]
    public function payOutstanding(string $token, Request $request): Response
    {
        $tenant = $this->tenantByToken($token);
        $this->checkCsrf($request, $tenant);

        if (!$this->charges->findOutstanding($tenant)) {
            return $this->redirectToRoute('billing_pay', ['token' => $token]);
        }

        return $this->toMollie($tenant, fn () => $this->billing->startOutstandingPayment($tenant));
    }

    #[Route('/billing/{token}/setup', name: 'billing_setup', requirements: ['token' => '[a-f0-9]{48}'], methods: ['POST'])]
    public function setup(string $token, Request $request): Response
    {
        $tenant = $this->tenantByToken($token);
        $this->checkCsrf($request, $tenant);

        $method = BillingService::METHOD_BANK === $request->request->get('method') ? BillingService::METHOD_BANK : BillingService::METHOD_CARD;

        return $this->toMollie($tenant, fn () => $this->billing->startMandateSetup($tenant, $method));
    }

    /** Mollie sends the client back here after the checkout. */
    #[Route('/billing/{token}/return', name: 'billing_return', requirements: ['token' => '[a-f0-9]{48}'], methods: ['GET'])]
    public function return(string $token): Response
    {
        $tenant = $this->tenantByToken($token);

        // Don't wait for the webhook: it may arrive later, or not at all locally
        if ($paymentId = $tenant->getLastCheckoutPaymentId()) {
            try {
                $this->billing->syncPayment($paymentId);
            } catch (MollieException $e) {
                $this->logger->error('Could not check payment {id}: {message}', ['id' => $paymentId, 'message' => $e->getMessage()]);
            }
        }

        // Started from the admin: go back there
        $user = $this->getUser();
        if ($user instanceof User && $user->getTenant()?->getId() === $tenant->getId()) {
            $this->charges->findOutstanding($tenant)
                ? $this->addFlash('warning', 'We have not received the payment yet. If you just paid, it can take a moment.')
                : $this->addFlash('success', $tenant->hasMandate() ? 'Thank you, everything is set up.' : 'The payment method was not set up. Please try again.');

            return $this->redirectToRoute('admin_billing_index');
        }

        return $this->redirectToRoute('billing_pay', ['token' => $token, 'returned' => 1]);
    }

    /**
     * Mollie calls this when a payment changes. It only sends the payment ID; the
     * status is always fetched from Mollie, so nothing in the request is trusted.
     */
    #[Route('/billing/webhook', name: 'billing_webhook', methods: ['POST'], priority: 10)]
    public function webhook(Request $request): Response
    {
        $paymentId = (string) $request->request->get('id');
        if (!preg_match('/^tr_[A-Za-z0-9]+$/', $paymentId)) {
            return new Response('', 200);
        }

        // Answered right away; the billing queue fetches the status (and retries if Mollie is down)
        $this->billing->queuePaymentSync($paymentId);

        return new Response('', 200);
    }

    private function tenantByToken(string $token): Tenant
    {
        return $this->tenants->findOneBy(['billingToken' => $token]) ?? throw new NotFoundHttpException();
    }

    private function checkCsrf(Request $request, Tenant $tenant): void
    {
        if (!$this->isCsrfTokenValid('billing-'.$tenant->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid form, please try again.');
        }
    }

    /** @param callable(): string $startCheckout */
    private function toMollie(Tenant $tenant, callable $startCheckout): Response
    {
        try {
            return new RedirectResponse($startCheckout(), 303);
        } catch (MollieException $e) {
            $this->logger->error('Could not start Mollie checkout for {tenant}: {message}', ['tenant' => $tenant->getSlug(), 'message' => $e->getMessage()]);
            $this->addFlash('billing_error', 'Online payment is not available right now. Please try again in a few minutes.');

            return $this->redirectToRoute('billing_pay', ['token' => $tenant->getBillingToken()]);
        }
    }
}

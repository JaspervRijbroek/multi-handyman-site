<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enquiry;
use App\Entity\Tenant;
use App\Form\ContactType;
use App\Repository\ProjectRepository;
use App\Repository\ServiceRepository;
use App\Tenant\TenantContext;
use App\Turnstile\Turnstile;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The public client websites. Which client is shown depends on the hostname,
 * resolved by App\EventListener\TenantRequestListener.
 */
final class SiteController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/', name: 'site_root', methods: ['GET'])]
    public function root(Request $request): Response
    {
        $tenant = $this->tenantContext->get();

        // The platform host: your own landing page
        if (null === $tenant) {
            return $this->render('platform/index.html.twig');
        }

        $locale = $request->getPreferredLanguage($tenant->getLocales()) ?? $tenant->getDefaultLocale();

        return $this->redirectToRoute('site_home', ['_locale' => $locale]);
    }

    #[Route('/{_locale}', name: 'site_home', requirements: ['_locale' => '[a-z]{2}'], methods: ['GET', 'POST'])]
    public function home(
        Request $request,
        ServiceRepository $services,
        ProjectRepository $projects,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        LoggerInterface $logger,
        Turnstile $turnstile,
        TranslatorInterface $translator,
        #[Autowire(env: 'MAILER_FROM')] string $mailerFrom,
    ): Response {
        $tenant = $this->siteTenant($request);

        $enquiry = (new Enquiry())->setTenant($tenant)->setLocale($request->getLocale());
        $form = $this->createForm(ContactType::class, $enquiry, [
            'action' => $this->generateUrl('site_home', ['_locale' => $request->getLocale()]).'#contact',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Bots fill in the hidden field: pretend everything went fine
            $isBot = (bool) $form->get(ContactType::HONEYPOT)->getData();

            if (!$isBot && !$turnstile->verify($request, 'contact')) {
                // Shown above the form, which keeps what the visitor typed
                $form->addError(new FormError($translator->trans('contact.turnstile_failed')));
            } else {
                if (!$isBot) {
                    $entityManager->persist($enquiry);
                    $entityManager->flush();
                    $this->notifyTenant($tenant, $enquiry, $mailer, $logger, $mailerFrom, $request->getHost());
                }

                $this->addFlash('contact_success', 'contact.success');

                return $this->redirect($this->generateUrl('site_home', ['_locale' => $request->getLocale()]).'#contact');
            }
        }

        return $this->render('site/home.html.twig', [
            'tenant' => $tenant,
            'services' => $services->findBy(['tenant' => $tenant], ['position' => 'ASC', 'id' => 'ASC']),
            'projects' => $projects->findBy(['tenant' => $tenant, 'published' => true], ['completedAt' => 'DESC', 'id' => 'DESC'], 12),
            'form' => $form,
        ]);
    }

    #[Route('/{_locale}/legal', name: 'site_legal', requirements: ['_locale' => '[a-z]{2}'], methods: ['GET'])]
    public function legal(Request $request): Response
    {
        return $this->render('site/legal.html.twig', [
            'tenant' => $this->siteTenant($request),
        ]);
    }

    #[Route('/robots.txt', name: 'site_robots', methods: ['GET'])]
    public function robots(): Response
    {
        $tenant = $this->tenantContext->get();
        $body = "User-agent: *\n";
        $body .= $tenant
            ? "Allow: /\n\nSitemap: ".$this->generateUrl('site_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL)."\n"
            : "Disallow: /admin\n";

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    #[Route('/sitemap.xml', name: 'site_sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $tenant = $this->tenantContext->get() ?? throw new NotFoundHttpException();

        $response = $this->render('site/sitemap.xml.twig', ['tenant' => $tenant]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }

    private function siteTenant(Request $request): Tenant
    {
        $tenant = $this->tenantContext->get() ?? throw new NotFoundHttpException();

        if (!$tenant->hasLocale($request->getLocale())) {
            throw new NotFoundHttpException();
        }

        return $tenant;
    }

    private function notifyTenant(Tenant $tenant, Enquiry $enquiry, MailerInterface $mailer, LoggerInterface $logger, string $from, string $host): void
    {
        if (!$tenant->getEmail()) {
            return;
        }

        $email = (new Email())
            ->from(new Address($from, $tenant->getName().' website'))
            ->to($tenant->getEmail())
            ->subject(\sprintf('New enquiry from %s via %s', $enquiry->getName(), $host))
            ->text(\sprintf(
                "Name: %s\nEmail / phone: %s\nLanguage: %s\n\n%s\n",
                $enquiry->getName(),
                $enquiry->getContact(),
                strtoupper($enquiry->getLocale()),
                $enquiry->getMessage(),
            ));

        if ($replyTo = $enquiry->getContactEmail()) {
            $email->replyTo($replyTo);
        }

        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // The enquiry is saved in the admin either way
            $logger->error('Could not send enquiry notification: {message}', ['message' => $e->getMessage()]);
        }
    }
}

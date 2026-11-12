<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enquiry;
use App\Entity\Review;
use App\Entity\Tenant;
use App\Form\ContactType;
use App\Form\ReviewType;
use App\Repository\ProjectRepository;
use App\Repository\ReviewRepository;
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

        // The platform host: the "find a pro" homepage in the visitor's language
        if (null === $tenant) {
            return $this->redirectToRoute('site_home', ['_locale' => PlatformController::preferredLocale($request)]);
        }

        $locale = $request->getPreferredLanguage($tenant->getLocales()) ?? $tenant->getDefaultLocale();

        return $this->redirectToRoute('site_home', ['_locale' => $locale]);
    }

    #[Route('/{_locale}', name: 'site_home', requirements: ['_locale' => '[a-z]{2}'], methods: ['GET', 'POST'])]
    public function home(
        Request $request,
        ServiceRepository $services,
        ProjectRepository $projects,
        ReviewRepository $reviews,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        LoggerInterface $logger,
        Turnstile $turnstile,
        TranslatorInterface $translator,
        #[Autowire(env: 'MAILER_FROM')] string $mailerFrom,
    ): Response {
        // The same URL on the platform host is the "find a pro" homepage
        if (!$this->tenantContext->has()) {
            return $this->forward(PlatformController::class.'::home', [
                '_locale' => $request->getLocale(),
                // Kept for the language switch and hreflang links in the template
                '_route' => 'site_home',
                '_route_params' => ['_locale' => $request->getLocale()],
            ]);
        }

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
            'reviews' => $reviews->findLatestPublished($tenant, 6),
            'form' => $form,
        ]);
    }

    #[Route('/{_locale}/review', name: 'site_review', requirements: ['_locale' => '[a-z]{2}'], methods: ['GET', 'POST'])]
    public function review(
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        LoggerInterface $logger,
        Turnstile $turnstile,
        TranslatorInterface $translator,
        #[Autowire(env: 'MAILER_FROM')] string $mailerFrom,
    ): Response {
        $tenant = $this->siteTenant($request);

        $review = (new Review())->setTenant($tenant)->setLocale($request->getLocale());
        $form = $this->createForm(ReviewType::class, $review);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $isBot = (bool) $form->get(ContactType::HONEYPOT)->getData();

            if (!$isBot && !$turnstile->verify($request, 'review')) {
                $form->addError(new FormError($translator->trans('contact.turnstile_failed')));
            } else {
                // Saved unpublished: the client decides in the admin whether it is shown
                if (!$isBot) {
                    $entityManager->persist($review);
                    $entityManager->flush();
                    $this->sendMail($tenant, $mailer, $logger, $mailerFrom, \sprintf('New review from %s via %s', $review->getAuthorName(), $request->getHost()), \sprintf(
                        "%s gave you %d out of 5 stars:\n\n%s\n\nPublish it on your website under Reviews in your admin.\n",
                        $review->getAuthorName(),
                        $review->getRating(),
                        $review->getText(),
                    ));
                }

                $this->addFlash('review_success', 'reviews.success');

                return $this->redirectToRoute('site_review', ['_locale' => $request->getLocale()]);
            }
        }

        return $this->render('site/review.html.twig', [
            'tenant' => $tenant,
            'form' => $form,
        ]);
    }

    /** The review link sent to a project's customer: no spam check needed, and the name is optional. */
    #[Route('/{_locale}/review/{token}', name: 'site_review_invite', requirements: ['_locale' => '[a-z]{2}', 'token' => '[0-9a-f]{32}'], methods: ['GET', 'POST'])]
    public function reviewInvite(
        string $token,
        Request $request,
        ProjectRepository $projects,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')] string $mailerFrom,
    ): Response {
        $tenant = $this->siteTenant($request);
        $project = $projects->findOneBy(['tenant' => $tenant, 'reviewToken' => $token]);

        // Unknown, used or older than a week
        if (!$project?->isReviewLinkValid()) {
            return $this->render('site/review_invite.html.twig', ['tenant' => $tenant, 'project' => null, 'form' => null], new Response('', Response::HTTP_GONE));
        }

        $review = (new Review())->setTenant($tenant)->setLocale($request->getLocale())->setProject($project);
        $form = $this->createForm(ReviewType::class, $review, ['allow_anonymous' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Saved unpublished, like other reviews: the client decides whether it is shown
            $project->completeReviewRequest();
            $entityManager->persist($review);
            $entityManager->flush();
            $this->sendMail($tenant, $mailer, $logger, $mailerFrom, \sprintf('New review from %s for "%s"', $review, $project), \sprintf(
                "%s gave you %d out of 5 stars for \"%s\":\n\n%s\n\nPublish it on your website under Reviews in your admin.\n",
                $review,
                $review->getRating(),
                $project,
                $review->getText(),
            ));

            $this->addFlash('review_success', 'reviews.success');

            return $this->redirectToRoute('site_review', ['_locale' => $request->getLocale()]);
        }

        return $this->render('site/review_invite.html.twig', [
            'tenant' => $tenant,
            'project' => $project,
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
        $this->sendMail($tenant, $mailer, $logger, $from, \sprintf('New enquiry from %s via %s', $enquiry->getName(), $host), \sprintf(
            "Name: %s\nEmail / phone: %s\nLanguage: %s\n\n%s\n",
            $enquiry->getName(),
            $enquiry->getContact(),
            strtoupper($enquiry->getLocale()),
            $enquiry->getMessage(),
        ), $enquiry->getContactEmail());
    }

    private function sendMail(Tenant $tenant, MailerInterface $mailer, LoggerInterface $logger, string $from, string $subject, string $text, ?string $replyTo = null): void
    {
        if (!$tenant->getEmail()) {
            return;
        }

        $email = (new Email())
            ->from(new Address($from, $tenant->getName().' website'))
            ->to($tenant->getEmail())
            ->subject($subject)
            ->text($text);

        if ($replyTo) {
            $email->replyTo($replyTo);
        }

        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // The enquiry or review is saved in the admin either way
            $logger->error('Could not send notification email: {message}', ['message' => $e->getMessage()]);
        }
    }
}

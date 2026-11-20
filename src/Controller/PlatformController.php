<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tenant;
use App\Entity\Trade;
use App\Search\ProSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public pages on PLATFORM_HOST, in all languages of LOCALES: the "find a pro"
 * homepage (/es, reached through SiteController), the search and the page for
 * tradespeople. TenantRequestListener returns 404 for these paths on client sites.
 */
final class PlatformController extends AbstractController
{
    /** Languages of the platform pages; the first is the default */
    public const array LOCALES = ['es', 'en', 'nl', 'de', 'fr'];

    /** Route requirement for {_locale} on the platform pages */
    public const string LOCALE_REQUIREMENT = 'es|en|nl|de|fr';

    /** Picks the visitor's language for the bare platform URLs (/ and /register) */
    public static function preferredLocale(Request $request): string
    {
        return $request->getPreferredLanguage(self::LOCALES) ?? self::LOCALES[0];
    }

    /** Called by SiteController::home for /{_locale} on the platform host */
    public function home(Request $request, ProSearch $search): Response
    {
        if (!\in_array($request->getLocale(), self::LOCALES, true)) {
            throw new NotFoundHttpException();
        }

        return $this->render('platform/home.html.twig', [
            'trades' => Trade::cases(),
            'languages' => Tenant::SUPPORTED_LOCALES,
            'trade_counts' => $search->tradeCounts(),
            'towns' => $search->towns(),
        ]);
    }

    #[Route('/{_locale}/search', name: 'platform_search', requirements: ['_locale' => self::LOCALE_REQUIREMENT], methods: ['GET'])]
    public function search(
        Request $request,
        ProSearch $search,
        #[Autowire(service: 'limiter.search_geocode')] RateLimiterFactoryInterface $geocodeLimiter,
    ): Response {
        $trade = Trade::tryFrom($request->query->getString('trade'));
        $language = $request->query->getString('language');
        $language = \in_array($language, Tenant::SUPPORTED_LOCALES, true) ? $language : null;
        $near = mb_substr(trim($request->query->getString('near')), 0, 80);
        $sort = ProSearch::SORT_RATING === $request->query->getString('sort') ? ProSearch::SORT_RATING : ProSearch::SORT_DISTANCE;
        $page = max(1, $request->query->getInt('page', 1));

        $found = $search->search($trade, $language, $near, $sort, $page, $geocodeLimiter->create($request->getClientIp()));

        if ($page > $found['pages']) {
            throw new NotFoundHttpException();
        }

        return $this->render('platform/search.html.twig', [
            'trade' => $trade,
            'language' => $language,
            'near' => $near,
            'sort' => $sort,
            'page' => $page,
            'pages' => $found['pages'],
            'total' => $found['total'],
            'results' => $found['results'],
            'located' => $found['located'],
            'town' => $found['town'],
            'trades' => Trade::cases(),
            'languages' => Tenant::SUPPORTED_LOCALES,
            'towns' => $search->towns(),
            'max_km' => ProSearch::MAX_DISTANCE_KM,
        ]);
    }

    /** Date of the current terms and privacy policy, shown on both pages. Change it when the texts change. */
    public const string LEGAL_UPDATED = '2026-11-15';

    #[Route('/{_locale}/terms', name: 'platform_terms', requirements: ['_locale' => self::LOCALE_REQUIREMENT], methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('platform/terms.html.twig');
    }

    #[Route('/{_locale}/privacy', name: 'platform_privacy', requirements: ['_locale' => self::LOCALE_REQUIREMENT], methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('platform/privacy.html.twig');
    }

    #[Route('/{_locale}/for-professionals', name: 'platform_pros', requirements: ['_locale' => self::LOCALE_REQUIREMENT], methods: ['GET'])]
    public function pros(): Response
    {
        return $this->render('platform/pros.html.twig');
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Trade;
use App\Search\ProSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public pages on PLATFORM_HOST besides the homepage (see SiteController::root):
 * the pro search and the page for tradespeople. TenantRequestListener returns
 * 404 for these paths on client sites.
 */
final class PlatformController extends AbstractController
{
    #[Route('/search', name: 'platform_search', methods: ['GET'])]
    public function search(
        Request $request,
        ProSearch $search,
        #[Autowire(service: 'limiter.search_geocode')] RateLimiterFactoryInterface $geocodeLimiter,
    ): Response {
        $trade = Trade::tryFrom($request->query->getString('trade'));
        $near = mb_substr(trim($request->query->getString('near')), 0, 80);
        $sort = ProSearch::SORT_RATING === $request->query->getString('sort') ? ProSearch::SORT_RATING : ProSearch::SORT_DISTANCE;

        $found = $search->search($trade, $near, $sort, $geocodeLimiter->create($request->getClientIp()));

        return $this->render('platform/search.html.twig', [
            'trade' => $trade,
            'near' => $near,
            'sort' => $sort,
            'results' => $found['results'],
            'located' => $found['located'],
            'trades' => Trade::cases(),
            'towns' => $search->towns(),
            'max_km' => ProSearch::MAX_DISTANCE_KM,
        ]);
    }

    #[Route('/for-professionals', name: 'platform_pros', methods: ['GET'])]
    public function pros(): Response
    {
        return $this->render('platform/pros.html.twig');
    }
}

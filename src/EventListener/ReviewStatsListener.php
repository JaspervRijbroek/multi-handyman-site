<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Review;
use App\Entity\Tenant;
use App\Repository\ReviewRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Keeps Tenant::$reviewCount and Tenant::$reviewAverage in line with the
 * client's published reviews, whenever a review is added, changed or removed.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ReviewStatsListener
{
    /** @var array<int, true> IDs of tenants whose reviews changed in this flush */
    private array $tenantIds = [];

    public function __construct(
        private readonly ReviewRepository $reviews,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->remember($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->remember($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->remember($args->getObject());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $tenantIds = array_keys($this->tenantIds);
        $this->tenantIds = [];

        foreach ($tenantIds as $tenantId) {
            $stats = $this->reviews->publishedStats($tenantId);

            // A DQL update, so no second flush is needed
            $args->getObjectManager()->createQuery(\sprintf(
                'UPDATE %s t SET t.reviewCount = :count, t.reviewAverage = :average WHERE t.id = :id',
                Tenant::class,
            ))
                ->setParameters(['count' => $stats['count'], 'average' => $stats['average'], 'id' => $tenantId])
                ->execute();
        }
    }

    private function remember(object $entity): void
    {
        if ($entity instanceof Review && $tenantId = $entity->getTenant()?->getId()) {
            $this->tenantIds[$tenantId] = true;
        }
    }
}

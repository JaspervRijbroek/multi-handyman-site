<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TurnstileWidget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TurnstileWidget>
 */
class TurnstileWidgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TurnstileWidget::class);
    }

    /** @return list<TurnstileWidget> oldest first */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['id' => 'ASC']);
    }

    /** The widget whose hostnames include this hostname or one of its parent domains. */
    public function findOneCovering(string $hostname): ?TurnstileWidget
    {
        // A handful of rows at most (one per ~10 client domains): matching in PHP is simplest
        foreach ($this->findAllOrdered() as $widget) {
            if ($widget->covers($hostname)) {
                return $widget;
            }
        }

        return null;
    }
}

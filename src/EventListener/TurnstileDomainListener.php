<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Domain;
use App\Entity\Tenant;
use App\Turnstile\TurnstileException;
use App\Turnstile\TurnstileWidgetSync;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Whenever a domain is added, changed or removed (also when a whole client is deleted),
 * update the Cloudflare Turnstile widgets once the response has been sent.
 * The admin's Domains screen syncs straight away instead, to show the result.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class TurnstileDomainListener
{
    public function __construct(
        private TurnstileWidgetSync $sync,
        private LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof Domain) {
                $this->sync->schedule();

                return;
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Tenant) {
                $this->sync->schedule();

                return;
            }
        }
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function syncIfPending(): void
    {
        if (!$this->sync->isPending()) {
            return;
        }

        try {
            $this->sync->sync();
        } catch (TurnstileException $e) {
            $this->logger->error('Could not update the Turnstile widgets, run app:turnstile:sync: {message}', ['message' => $e->getMessage()]);
        }
    }
}

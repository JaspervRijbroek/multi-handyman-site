<?php

declare(strict_types=1);

namespace App\Turnstile;

use App\Entity\TurnstileWidget;
use App\Repository\DomainRepository;
use App\Repository\TurnstileWidgetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps the hostnames of our Cloudflare Turnstile widgets in line with the domain table,
 * so the widget works on every client site (plus PLATFORM_HOST for the login form).
 *
 * The full list is rebuilt on every run, which makes it safe to repeat: new domains are
 * added, removed ones are taken off, and a widget is created when the others are full.
 * Hostnames stay on the widget they were given, so pages already open keep working.
 */
final class TurnstileWidgetSync
{
    private bool $pending = false;

    public function __construct(
        private readonly CloudflareTurnstileApi $api,
        private readonly DomainRepository $domains,
        private readonly TurnstileWidgetRepository $widgets,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'PLATFORM_HOST')]
        private readonly string $platformHost,
        #[Autowire(env: 'int:TURNSTILE_HOSTNAMES_PER_WIDGET')]
        private readonly int $hostnamesPerWidget,
        #[Autowire(env: 'TURNSTILE_WIDGET_MODE')]
        private readonly string $widgetMode,
        #[Autowire('%kernel.project_dir%/var/turnstile-sync.lock')]
        private readonly string $lockFile,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->api->isConfigured();
    }

    /** Domains changed: sync at the end of this request or command (see TurnstileDomainListener). */
    public function schedule(): void
    {
        $this->pending = $this->isEnabled();
    }

    public function isPending(): bool
    {
        return $this->pending;
    }

    /**
     * @return array{hostnames: int, widgets: int, created: int, updated: int, deleted: int}
     *
     * @throws TurnstileException when Cloudflare cannot be reached or refuses a change.
     *                            What was done before the error is saved; just run it again.
     */
    public function sync(): array
    {
        $this->pending = false;

        if (!$this->isEnabled()) {
            throw new TurnstileException('Set CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN to manage Turnstile widgets.');
        }

        // Admin saves, cron and the end-of-request sync could overlap: one at a time
        $lock = fopen($this->lockFile, 'c') ?: throw new TurnstileException('Cannot open '.$this->lockFile);
        flock($lock, \LOCK_EX);

        try {
            return $this->doSync();
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    private function doSync(): array
    {
        $capacity = max(1, $this->hostnamesPerWidget);
        $stats = ['hostnames' => 0, 'widgets' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0];

        $wanted = Hostnames::collapse([...$this->domains->findAllHostnames(), $this->platformHost]);
        $stats['hostnames'] = \count($wanted);

        // 1. Plan: hostnames stay on their widget, new ones fill up free places
        $widgets = $this->widgets->findAllOrdered();
        $plan = [];
        $placed = [];
        foreach ($widgets as $i => $widget) {
            $keep = array_values(array_diff(array_intersect($widget->getHostnames(), $wanted), $placed));
            $plan[$i] = \array_slice($keep, 0, $capacity);
            array_push($placed, ...$plan[$i]);
        }

        $unplaced = array_values(array_diff($wanted, $placed));
        foreach ($plan as $i => $list) {
            $free = $capacity - \count($list);
            if ($free > 0 && $unplaced) {
                $plan[$i] = [...$list, ...array_splice($unplaced, 0, $free)];
            }
        }

        // 2. Apply changes to existing widgets
        foreach ($widgets as $i => $widget) {
            $list = $plan[$i];
            sort($list);

            if (!$list) {
                $this->api->deleteWidget($widget->getSiteKey());
                $this->entityManager->remove($widget);
                $this->entityManager->flush();
                ++$stats['deleted'];
                $this->logger->info('Deleted empty Turnstile widget {sitekey}', ['sitekey' => $widget->getSiteKey()]);

                continue;
            }

            ++$stats['widgets'];
            $current = $this->api->getWidget($widget->getSiteKey());

            if (null === $current) {
                // Deleted in the Cloudflare dashboard: make a new one with the same hostnames
                $created = $this->api->createWidget($this->widgetName($i + 1), $list, $this->widgetMode);
                $widget->replaceKeys($created['sitekey'], $created['secret']);
                ++$stats['created'];
                $this->logger->warning('Turnstile widget was missing on Cloudflare, created {sitekey} instead', ['sitekey' => $created['sitekey']]);
            } else {
                $remote = $current['domains'] ?? [];
                sort($remote);
                if ($remote !== $list) {
                    $this->api->updateDomains($widget->getSiteKey(), $current, $list);
                    ++$stats['updated'];
                }
            }

            $widget->setHostnames($list)->markSynced();
            $this->entityManager->flush();
        }

        // 3. Everything is full: new widgets for the rest
        $number = \count($widgets);
        foreach (array_chunk($unplaced, $capacity) as $list) {
            $created = $this->api->createWidget($this->widgetName(++$number), $list, $this->widgetMode);
            $widget = (new TurnstileWidget($created['sitekey'], $created['secret']))->setHostnames($list)->markSynced();
            $this->entityManager->persist($widget);
            $this->entityManager->flush();
            ++$stats['created'];
            ++$stats['widgets'];
            $this->logger->info('Created Turnstile widget {sitekey} for {hostnames}', ['sitekey' => $created['sitekey'], 'hostnames' => implode(', ', $list)]);
        }

        return $stats;
    }

    private function widgetName(int $number): string
    {
        return \sprintf('Client sites (%s) #%d', $this->platformHost, $number);
    }
}

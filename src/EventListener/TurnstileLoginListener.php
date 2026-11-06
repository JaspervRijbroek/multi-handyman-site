<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Turnstile\Turnstile;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/** The login form needs a Turnstile pass before the password is even checked. */
final readonly class TurnstileLoginListener
{
    public function __construct(
        private Turnstile $turnstile,
        private RequestStack $requestStack,
    ) {
    }

    // Before the password check (priority 0), so bots cannot test passwords
    #[AsEventListener(event: CheckPassportEvent::class, priority: 256)]
    public function checkPassport(CheckPassportEvent $event): void
    {
        $request = $this->requestStack->getMainRequest();

        if (null === $request || 'app_login' !== $request->attributes->get('_route') || !$request->isMethod('POST')) {
            return;
        }

        if (!$this->turnstile->verify($request, 'login')) {
            throw new CustomUserMessageAuthenticationException('Please confirm you are not a robot and try again.');
        }
    }
}

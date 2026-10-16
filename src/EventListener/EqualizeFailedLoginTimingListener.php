<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\LoginTimingEqualizer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * POST /api/login: an unknown email or unverified account fails before the password is
 * hashed. Hash it anyway, so the response time doesn't reveal which emails have accounts.
 */
#[AsEventListener]
final class EqualizeFailedLoginTimingListener
{
    public function __construct(
        private readonly LoginTimingEqualizer $equalizer,
    ) {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        $exception = $event->getException();
        // Symfony hides these behind BadCredentialsException; the original is the previous one.
        $original = $exception instanceof BadCredentialsException ? ($exception->getPrevious() ?? $exception) : $exception;
        if (!$original instanceof UserNotFoundException && !$original instanceof AccountStatusException) {
            return;
        }

        $credentials = $event->getPassport()?->getBadge(PasswordCredentials::class);
        if (!$credentials instanceof PasswordCredentials || $credentials->isResolved()) {
            return;
        }

        $this->equalizer->spendPasswordCheck($credentials->getPassword());
    }
}

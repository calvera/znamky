<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Spends one password hash on a login that failed before any password was checked
 * (unknown email, unverified account), so its response time matches a wrong password.
 */
final class LoginTimingEqualizer
{
    public function __construct(
        private readonly PasswordHasherFactoryInterface $hasherFactory,
    ) {
    }

    public function spendPasswordCheck(#[\SensitiveParameter] string $plainPassword): void
    {
        try {
            $this->hasherFactory->getPasswordHasher(User::class)->hash($plainPassword);
        } catch (InvalidPasswordException) {
            // Over the hasher's length limit: a real check rejects it just as fast.
        }
    }
}

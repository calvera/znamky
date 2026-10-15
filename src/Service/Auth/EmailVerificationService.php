<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Repository\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EmailVerificationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EmailTokenLookup $emailTokenLookup,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function verify(string $rawToken, string $plainPassword): void
    {
        $user = $this->emailTokenLookup->findVerificationUser($rawToken);

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setIsVerified(true);
        $user->setEmailVerificationToken(null);
        $user->setEmailVerificationTokenExpiresAt(null);

        $this->userRepository->save($user);
    }
}

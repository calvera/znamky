<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Repository\UserRepository;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EmailVerificationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TokenHasher $tokenHasher,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function verify(string $rawToken, string $plainPassword): void
    {
        $user = $this->userRepository->findOneByEmailVerificationTokenHash(
            $this->tokenHasher->hash($rawToken)
        );

        if (null === $user) {
            throw new UnprocessableEntityHttpException('Invalid verification token.');
        }

        $expiresAt = $user->getEmailVerificationTokenExpiresAt();
        if (null === $expiresAt || $expiresAt < new \DateTimeImmutable()) {
            throw new UnprocessableEntityHttpException('Verification token has expired.');
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setIsVerified(true);
        $user->setEmailVerificationToken(null);
        $user->setEmailVerificationTokenExpiresAt(null);

        $this->userRepository->save($user);
    }
}

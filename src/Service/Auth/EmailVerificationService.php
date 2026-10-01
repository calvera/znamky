<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Repository\UserRepository;
use App\Security\TokenHasher;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class EmailVerificationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TokenHasher $tokenHasher,
    ) {
    }

    public function verify(string $rawToken): void
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

        $user->setIsVerified(true);
        $user->setEmailVerificationToken(null);
        $user->setEmailVerificationTokenExpiresAt(null);

        $this->userRepository->save($user);
    }
}

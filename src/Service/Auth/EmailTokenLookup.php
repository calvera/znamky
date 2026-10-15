<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Finds the user holding an unexpired emailed token, or rejects the request with 422.
 */
final class EmailTokenLookup
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TokenHasher $tokenHasher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function findVerificationUser(string $rawToken): User
    {
        $user = $this->userRepository->findOneByEmailVerificationTokenHash($this->tokenHasher->hash($rawToken));

        return $this->requireUnexpired($user, $user?->getEmailVerificationTokenExpiresAt(), 'verification token');
    }

    public function findPasswordResetUser(string $rawToken): User
    {
        $user = $this->userRepository->findOneByPasswordResetTokenHash($this->tokenHasher->hash($rawToken));

        return $this->requireUnexpired($user, $user?->getPasswordResetTokenExpiresAt(), 'password reset token');
    }

    private function requireUnexpired(?User $user, ?\DateTimeImmutable $expiresAt, string $label): User
    {
        if (null === $user) {
            throw new UnprocessableEntityHttpException(\sprintf('Invalid %s.', $label));
        }

        if (null === $expiresAt || $expiresAt < $this->clock->now()) {
            throw new UnprocessableEntityHttpException(\sprintf('%s has expired.', ucfirst($label)));
        }

        return $user;
    }
}

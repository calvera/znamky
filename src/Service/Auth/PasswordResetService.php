<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RevokeRefreshTokenManagerInterface;
use Safe\DateTimeImmutable;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenHasher $tokenHasher,
        private readonly AuthMailer $authMailer,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RevokeRefreshTokenManagerInterface $refreshTokenManager,
    ) {
    }

    public function requestReset(string $email, string $locale = 'en'): void
    {
        $user = $this->userRepository->findOneByEmail($email);
        if (null === $user) {
            return;
        }

        $rawToken = $this->tokenHasher->generate();
        $user->setPasswordResetToken($this->tokenHasher->hash($rawToken));
        $user->setPasswordResetTokenExpiresAt(new DateTimeImmutable('+1 hour'));

        // Replacing the stored token before the email is accepted burns the
        // previous link when SMTP fails. Roll that replacement back with the send.
        $this->entityManager->wrapInTransaction(function () use ($user, $rawToken, $locale): void {
            $this->userRepository->save($user);
            $this->authMailer->sendPasswordReset($user, $rawToken, $locale);
        });
    }

    public function reset(string $rawToken, string $plainPassword): void
    {
        $user = $this->userRepository->findOneByPasswordResetTokenHash(
            $this->tokenHasher->hash($rawToken)
        );

        if (null === $user) {
            throw new UnprocessableEntityHttpException('Invalid password reset token.');
        }

        $expiresAt = $user->getPasswordResetTokenExpiresAt();
        if (null === $expiresAt || $expiresAt < new DateTimeImmutable()) {
            throw new UnprocessableEntityHttpException('Password reset token has expired.');
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setPasswordResetToken(null);
        $user->setPasswordResetTokenExpiresAt(null);

        $this->userRepository->save($user);
        $this->refreshTokenManager->revokeAllForUser($user);
    }
}

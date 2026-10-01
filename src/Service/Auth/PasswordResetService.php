<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\TokenHasher;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RevokeRefreshTokenManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class PasswordResetService
{
    public function __construct(
        private UserRepository $userRepository,
        private TokenHasher $tokenHasher,
        private AuthMailer $authMailer,
        private UserPasswordHasherInterface $passwordHasher,
        private RefreshTokenManagerInterface $refreshTokenManager,
    ) {
    }

    public function requestReset(string $email): void
    {
        $user = $this->userRepository->findOneByEmail($email);
        if (null === $user) {
            return;
        }

        $rawToken = $this->tokenHasher->generate();
        $user->setPasswordResetToken($this->tokenHasher->hash($rawToken));
        $user->setPasswordResetTokenExpiresAt(new \DateTimeImmutable('+1 hour'));

        $this->userRepository->save($user);
        $this->authMailer->sendPasswordReset($user, $rawToken);
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
        if (null === $expiresAt || $expiresAt < new \DateTimeImmutable()) {
            throw new UnprocessableEntityHttpException('Password reset token has expired.');
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setPasswordResetToken(null);
        $user->setPasswordResetTokenExpiresAt(null);

        $this->userRepository->save($user);
        $this->revokeRefreshTokens($user);
    }

    private function revokeRefreshTokens(User $user): void
    {
        if ($this->refreshTokenManager instanceof RevokeRefreshTokenManagerInterface) {
            $this->refreshTokenManager->revokeAllForUser($user);
        }
    }
}

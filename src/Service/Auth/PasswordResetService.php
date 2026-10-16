<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RevokeRefreshTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class PasswordResetService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenHasher $tokenHasher,
        private readonly EmailTokenLookup $emailTokenLookup,
        private readonly AuthMailer $authMailer,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RevokeRefreshTokenManagerInterface $refreshTokenManager,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.auth_reset_email')]
        private readonly RateLimiterFactoryInterface $resetEmailLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Mails a reset token; over the per-address limit it silently sends nothing.
     */
    public function requestReset(string $email, string $locale = 'en'): void
    {
        if (!$this->resetEmailLimiter->create(strtolower(trim($email)))->consume()->isAccepted()) {
            return;
        }

        $user = $this->userRepository->findOneByEmail($email);
        if (null === $user) {
            return;
        }

        $rawToken = $this->tokenHasher->generate();
        $user->setPasswordResetToken($this->tokenHasher->hash($rawToken));
        $user->setPasswordResetTokenExpiresAt($this->clock->now()->modify('+1 hour'));

        // Replacing the stored token before the email is accepted burns the
        // previous link when SMTP fails. Roll that replacement back with the send.
        // The failure is only logged: an error here, and not for unknown addresses,
        // would reveal which emails have accounts.
        try {
            $this->entityManager->wrapInTransaction(function () use ($user, $rawToken, $locale): void {
                $this->userRepository->save($user);
                $this->authMailer->sendPasswordReset($user, $rawToken, $locale);
            });
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Password reset email could not be sent.', ['exception' => $e]);
        }
    }

    public function reset(string $rawToken, string $plainPassword): void
    {
        $user = $this->emailTokenLookup->findPasswordResetUser($rawToken);

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setPasswordResetToken(null);
        $user->setPasswordResetTokenExpiresAt(null);

        $this->userRepository->save($user);
        $this->refreshTokenManager->revokeAllForUser($user);
    }
}

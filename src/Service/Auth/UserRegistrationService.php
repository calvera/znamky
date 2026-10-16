<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Dto\Auth\RegisterRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class UserRegistrationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenHasher $tokenHasher,
        private readonly AuthMailer $authMailer,
        private readonly ValidatorInterface $validator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function register(RegisterRequest $request): User
    {
        $existing = $this->userRepository->findOneByEmail($request->email);
        if (null !== $existing && !$existing->isVerified()) {
            return $this->issueVerification($existing, $request->locale);
        }

        $user = new User();
        $user->setEmail($request->email);

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($user, $violations);
        }

        $user->setIsVerified(false);

        return $this->issueVerification($user, $request->locale);
    }

    /**
     * Sets a fresh verification token and saves the user only if the email is sent.
     */
    private function issueVerification(User $user, string $locale): User
    {
        $rawToken = $this->tokenHasher->generate();
        $user->setEmailVerificationToken($this->tokenHasher->hash($rawToken));
        $user->setEmailVerificationTokenExpiresAt($this->clock->now()->modify('+1 day'));

        // The raw token exists only in the email. Commit the account only after
        // that send succeeds, or a transient SMTP failure leaves an address that
        // can never be verified and can never be registered again.
        $this->entityManager->wrapInTransaction(function () use ($user, $rawToken, $locale): void {
            $this->userRepository->save($user);
            $this->authMailer->sendEmailVerification($user, $rawToken, $locale);
        });

        return $user;
    }
}

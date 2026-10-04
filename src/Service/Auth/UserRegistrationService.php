<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Dto\Auth\RegisterRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
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
    ) {
    }

    public function register(RegisterRequest $request): User
    {
        $user = new User();
        $user->setEmail($request->email);

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($user, $violations);
        }

        $rawToken = $this->tokenHasher->generate();
        $user->setEmailVerificationToken($this->tokenHasher->hash($rawToken));
        $user->setEmailVerificationTokenExpiresAt(new \DateTimeImmutable('+1 day'));
        $user->setIsVerified(false);

        // The raw token exists only in the email. Commit the account only after
        // that send succeeds, or a transient SMTP failure leaves an address that
        // can never be verified and can never be registered again.
        $this->entityManager->wrapInTransaction(function () use ($user, $rawToken): void {
            $this->userRepository->save($user);
            $this->authMailer->sendEmailVerification($user, $rawToken);
        });

        return $user;
    }
}

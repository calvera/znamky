<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Dto\Auth\RegisterRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\TokenHasher;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class UserRegistrationService
{
    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private TokenHasher $tokenHasher,
        private AuthMailer $authMailer,
        private ValidatorInterface $validator,
    ) {
    }

    public function register(RegisterRequest $request): User
    {
        $user = new User();
        $user->setEmail($request->email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $request->password));

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($user, $violations);
        }

        $rawToken = $this->tokenHasher->generate();
        $user->setEmailVerificationToken($this->tokenHasher->hash($rawToken));
        $user->setEmailVerificationTokenExpiresAt(new \DateTimeImmutable('+1 day'));
        $user->setIsVerified(false);

        $this->userRepository->save($user);
        $this->authMailer->sendEmailVerification($user, $rawToken);

        return $user;
    }
}

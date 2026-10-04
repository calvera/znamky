<?php

declare(strict_types=1);

namespace App\GraphQl\Resolver\Auth;

use ApiPlatform\GraphQl\Resolver\MutationResolverInterface;
use App\ApiResource\Auth;
use App\Dto\Auth\RegisterRequest;
use App\GraphQl\Auth\AuthInputValidator;
use App\GraphQl\Auth\AuthRateLimiter;
use App\Service\Auth\UserRegistrationService;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class RegisterMutationResolver implements MutationResolverInterface
{
    public function __construct(
        private readonly UserRegistrationService $registrationService,
        private readonly AuthInputValidator $inputValidator,
        private readonly AuthRateLimiter $rateLimiter,
    ) {
    }

    public function __invoke(?object $item, array $context): object
    {
        $this->rateLimiter->consumeEmail();

        $input = $this->inputValidator->input($context);
        $request = new RegisterRequest(
            $this->inputValidator->string($input, 'email'),
            $this->inputValidator->string($input, 'password'),
        );
        $this->inputValidator->validate($request);

        try {
            $user = $this->registrationService->register($request);
        } catch (ValidationFailedException $exception) {
            $this->inputValidator->convert($exception);
        }

        $id = $user->getId();
        if (null === $id) {
            throw new \LogicException('Registered user must have an id.');
        }

        return Auth::registered($id, $user->getEmail());
    }
}

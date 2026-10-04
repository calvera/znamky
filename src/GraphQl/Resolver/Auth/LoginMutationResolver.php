<?php

declare(strict_types=1);

namespace App\GraphQl\Resolver\Auth;

use ApiPlatform\GraphQl\Resolver\MutationResolverInterface;
use App\ApiResource\Auth;
use App\Dto\Auth\LoginRequest;
use App\GraphQl\Auth\AuthInputValidator;
use App\Service\Auth\AuthTokenService;

final class LoginMutationResolver implements MutationResolverInterface
{
    public function __construct(
        private readonly AuthTokenService $authTokenService,
        private readonly AuthInputValidator $inputValidator,
    ) {
    }

    public function __invoke(?object $item, array $context): object
    {
        $input = $this->inputValidator->input($context);
        $request = new LoginRequest(
            $this->inputValidator->string($input, 'email'),
            $this->inputValidator->string($input, 'password'),
        );
        $this->inputValidator->validate($request);

        $tokens = $this->authTokenService->login($request->email, $request->password);

        return Auth::tokens($tokens['token'], $tokens['refresh_token']);
    }
}

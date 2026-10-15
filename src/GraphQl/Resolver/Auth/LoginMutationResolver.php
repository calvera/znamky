<?php

declare(strict_types=1);

namespace App\GraphQl\Resolver\Auth;

use ApiPlatform\GraphQl\Resolver\MutationResolverInterface;
use App\ApiResource\Auth;
use App\Dto\Auth\LoginRequest;
use App\GraphQl\Auth\AuthInputValidator;
use App\Service\Auth\LoginService;

final class LoginMutationResolver implements MutationResolverInterface
{
    public function __construct(
        private readonly LoginService $loginService,
        private readonly AuthInputValidator $inputValidator,
    ) {
    }

    public function __invoke(?object $item, array $context): object
    {
        $request = $this->inputValidator->dto($context, LoginRequest::class);

        $tokens = $this->loginService->login($request->email, $request->password);

        return Auth::tokens($tokens['token'], $tokens['refresh_token']);
    }
}

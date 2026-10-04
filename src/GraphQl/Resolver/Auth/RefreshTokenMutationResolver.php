<?php

declare(strict_types=1);

namespace App\GraphQl\Resolver\Auth;

use ApiPlatform\GraphQl\Resolver\MutationResolverInterface;
use App\ApiResource\Auth;
use App\Dto\Auth\RefreshTokenRequest;
use App\GraphQl\Auth\AuthInputValidator;
use App\Service\Auth\AuthTokenService;

final class RefreshTokenMutationResolver implements MutationResolverInterface
{
    public function __construct(
        private readonly AuthTokenService $authTokenService,
        private readonly AuthInputValidator $inputValidator,
    ) {
    }

    public function __invoke(?object $item, array $context): object
    {
        $input = $this->inputValidator->input($context);
        $request = new RefreshTokenRequest(
            $this->inputValidator->string($input, 'refreshToken'),
        );
        $this->inputValidator->validate($request);

        $tokens = $this->authTokenService->refresh($request->refreshToken);

        return Auth::tokens($tokens['token'], $tokens['refresh_token']);
    }
}

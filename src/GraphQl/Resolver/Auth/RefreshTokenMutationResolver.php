<?php

declare(strict_types=1);

namespace App\GraphQl\Resolver\Auth;

use ApiPlatform\GraphQl\Resolver\MutationResolverInterface;
use App\ApiResource\Auth;
use App\Dto\Auth\RefreshTokenRequest;
use App\GraphQl\Auth\AuthInputValidator;
use App\Service\Auth\RefreshTokenRotator;

final class RefreshTokenMutationResolver implements MutationResolverInterface
{
    public function __construct(
        private readonly RefreshTokenRotator $refreshTokenRotator,
        private readonly AuthInputValidator $inputValidator,
    ) {
    }

    public function __invoke(?object $item, array $context): object
    {
        $request = $this->inputValidator->dto($context, RefreshTokenRequest::class);

        $tokens = $this->refreshTokenRotator->rotate($request->refreshToken);

        return Auth::tokens($tokens['token'], $tokens['refresh_token']);
    }
}

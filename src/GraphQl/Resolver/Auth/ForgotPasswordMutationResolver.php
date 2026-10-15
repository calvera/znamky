<?php

declare(strict_types=1);

namespace App\GraphQl\Resolver\Auth;

use ApiPlatform\GraphQl\Resolver\MutationResolverInterface;
use App\ApiResource\Auth;
use App\Dto\Auth\ForgotPasswordRequest;
use App\GraphQl\Auth\AuthInputValidator;
use App\GraphQl\Auth\AuthRateLimiter;
use App\Service\Auth\PasswordResetService;

final class ForgotPasswordMutationResolver implements MutationResolverInterface
{
    public function __construct(
        private readonly PasswordResetService $passwordResetService,
        private readonly AuthInputValidator $inputValidator,
        private readonly AuthRateLimiter $rateLimiter,
    ) {
    }

    public function __invoke(?object $item, array $context): object
    {
        $this->rateLimiter->consumeEmail();

        $request = $this->inputValidator->dto($context, ForgotPasswordRequest::class);

        $this->passwordResetService->requestReset($request->email, $request->locale);

        return Auth::success();
    }
}

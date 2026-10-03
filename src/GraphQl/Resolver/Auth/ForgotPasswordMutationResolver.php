<?php

declare(strict_types=1);

namespace App\GraphQl\Resolver\Auth;

use ApiPlatform\GraphQl\Resolver\MutationResolverInterface;
use App\ApiResource\Auth;
use App\Dto\Auth\ForgotPasswordRequest;
use App\GraphQl\AuthInputValidator;
use App\GraphQl\AuthRateLimiter;
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

        $input = $this->inputValidator->input($context);
        $request = new ForgotPasswordRequest(
            $this->inputValidator->string($input, 'email'),
        );
        $this->inputValidator->validate($request);

        $this->passwordResetService->requestReset($request->email);

        return Auth::success();
    }
}

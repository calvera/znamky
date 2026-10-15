<?php

declare(strict_types=1);

namespace App\GraphQl\Resolver\Auth;

use ApiPlatform\GraphQl\Resolver\MutationResolverInterface;
use App\ApiResource\Auth;
use App\Dto\Auth\VerifyEmailRequest;
use App\GraphQl\Auth\AuthInputValidator;
use App\GraphQl\Auth\AuthRateLimiter;
use App\Service\Auth\EmailVerificationService;

final class VerifyEmailMutationResolver implements MutationResolverInterface
{
    public function __construct(
        private readonly EmailVerificationService $emailVerificationService,
        private readonly AuthInputValidator $inputValidator,
        private readonly AuthRateLimiter $rateLimiter,
    ) {
    }

    public function __invoke(?object $item, array $context): object
    {
        $this->rateLimiter->consumeToken();

        $request = $this->inputValidator->dto($context, VerifyEmailRequest::class);

        $this->emailVerificationService->verify($request->token, $request->password);

        return Auth::success();
    }
}

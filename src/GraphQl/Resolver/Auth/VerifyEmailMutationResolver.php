<?php

declare(strict_types=1);

namespace App\GraphQl\Resolver\Auth;

use ApiPlatform\GraphQl\Resolver\MutationResolverInterface;
use App\ApiResource\Auth;
use App\Dto\Auth\VerifyEmailRequest;
use App\GraphQl\AuthInputValidator;
use App\GraphQl\AuthRateLimiter;
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

        $input = $this->inputValidator->input($context);
        $request = new VerifyEmailRequest(
            $this->inputValidator->string($input, 'token'),
            $this->inputValidator->string($input, 'password'),
        );
        $this->inputValidator->validate($request);

        $this->emailVerificationService->verify($request->token, $request->password);

        return Auth::success();
    }
}

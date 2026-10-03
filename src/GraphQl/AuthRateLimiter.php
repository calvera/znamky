<?php

declare(strict_types=1);

namespace App\GraphQl;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class AuthRateLimiter
{
    public function __construct(
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'limiter.auth_email')]
        private readonly RateLimiterFactoryInterface $authEmailLimiter,
        #[Autowire(service: 'limiter.auth_token')]
        private readonly RateLimiterFactoryInterface $authTokenLimiter,
    ) {
    }

    public function consumeEmail(): void
    {
        $this->consume($this->authEmailLimiter);
    }

    public function consumeToken(): void
    {
        $this->consume($this->authTokenLimiter);
    }

    private function consume(RateLimiterFactoryInterface $factory): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $key = $request?->getClientIp() ?? 'unknown';
        $limit = $factory->create($key)->consume();

        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
        throw new TooManyRequestsHttpException($retryAfter, Response::$statusTexts[Response::HTTP_TOO_MANY_REQUESTS] ?? 'Too Many Requests');
    }
}

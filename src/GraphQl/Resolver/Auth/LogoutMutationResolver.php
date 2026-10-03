<?php

declare(strict_types=1);

namespace App\GraphQl\Resolver\Auth;

use ApiPlatform\GraphQl\Resolver\MutationResolverInterface;
use App\ApiResource\Auth;
use App\Entity\User;
use App\GraphQl\AuthInputValidator;
use App\Service\Auth\LogoutService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class LogoutMutationResolver implements MutationResolverInterface
{
    public function __construct(
        private readonly LogoutService $logoutService,
        private readonly AuthInputValidator $inputValidator,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(?object $item, array $context): object
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Access Denied.');
        }

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            throw new \LogicException('Logout requires an HTTP request.');
        }

        $input = $this->inputValidator->input($context);
        $refreshToken = $input['refreshToken'] ?? null;
        $refreshToken = \is_string($refreshToken) && '' !== $refreshToken ? $refreshToken : null;

        $this->logoutService->logout($user, $request, $refreshToken);

        return Auth::success();
    }
}

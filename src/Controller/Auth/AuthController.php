<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Dto\Auth\ForgotPasswordRequest;
use App\Dto\Auth\LogoutRequest;
use App\Dto\Auth\RegisterRequest;
use App\Dto\Auth\ResetPasswordRequest;
use App\Dto\Auth\VerifyEmailRequest;
use App\Entity\User;
use App\Service\Auth\EmailVerificationService;
use App\Service\Auth\LogoutService;
use App\Service\Auth\PasswordResetService;
use App\Service\Auth\UserRegistrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
final class AuthController extends AbstractController
{
    #[RateLimit('auth_email')]
    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload]
        RegisterRequest $request,
        UserRegistrationService $registrationService,
    ): JsonResponse {
        $user = $registrationService->register($request);

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
        ], Response::HTTP_CREATED);
    }

    #[RateLimit('auth_token')]
    #[Route('/verify-email', name: 'api_verify_email', methods: ['POST'])]
    public function verifyEmail(
        #[MapRequestPayload]
        VerifyEmailRequest $request,
        EmailVerificationService $emailVerificationService,
    ): Response {
        $emailVerificationService->verify($request->token, $request->password);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('Login is handled by the security firewall.');
    }

    #[Route('/token/refresh', name: 'api_token_refresh', methods: ['POST'])]
    public function refresh(): never
    {
        throw new \LogicException('Token refresh is handled by the security firewall.');
    }

    #[RateLimit('auth_email')]
    #[Route('/forgot-password', name: 'api_forgot_password', methods: ['POST'])]
    public function forgotPassword(
        #[MapRequestPayload]
        ForgotPasswordRequest $request,
        PasswordResetService $passwordResetService,
    ): Response {
        $passwordResetService->requestReset($request->email, $request->locale);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[RateLimit('auth_token')]
    #[Route('/reset-password', name: 'api_reset_password', methods: ['POST'])]
    public function resetPassword(
        #[MapRequestPayload]
        ResetPasswordRequest $request,
        PasswordResetService $passwordResetService,
    ): Response {
        $passwordResetService->reset($request->token, $request->password);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route('/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(
        #[CurrentUser]
        User $user,
        #[MapRequestPayload]
        LogoutRequest $logoutRequest,
        Request $request,
        LogoutService $logoutService,
    ): Response {
        $logoutService->logout($user, $request, $logoutRequest->refresh_token);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
        ]);
    }
}

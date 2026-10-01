<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RevokeRefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\BlockedTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class LogoutService
{
    public function __construct(
        private RefreshTokenManagerInterface $refreshTokenManager,
        private BlockedTokenManagerInterface $blockedTokenManager,
        private JWTTokenManagerInterface $jwtManager,
        private TokenExtractorInterface $tokenExtractor,
    ) {
    }

    public function logout(User $user, Request $request, ?string $refreshToken): void
    {
        $this->blockAccessToken($request);

        if (null !== $refreshToken && '' !== $refreshToken) {
            $stored = $this->refreshTokenManager->get($refreshToken);
            if (null !== $stored && $stored->getUsername() === $user->getUserIdentifier()) {
                $this->refreshTokenManager->delete($stored);
            }

            return;
        }

        if ($this->refreshTokenManager instanceof RevokeRefreshTokenManagerInterface) {
            $this->refreshTokenManager->revokeAllForUser($user);
        }
    }

    private function blockAccessToken(Request $request): void
    {
        $token = $this->tokenExtractor->extract($request);
        if (false === $token) {
            return;
        }

        try {
            $payload = $this->jwtManager->parse($token);
            $this->blockedTokenManager->add($payload);
        } catch (\Throwable) {
            // Token already invalid or missing blocklist claims — nothing to block.
        }
    }
}

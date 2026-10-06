<?php

declare(strict_types=1);

namespace App\Security;

use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Gesdinet\JWTRefreshTokenBundle\Request\Extractor\ExtractorInterface;
use Gesdinet\JWTRefreshTokenBundle\Security\ReuseDetection\SpentRefreshToken;
use Gesdinet\JWTRefreshTokenBundle\Security\ReuseDetection\SpentRefreshTokenRegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Gesdinet remembers spent tokens via the entity's stored value. With hash_tokens
 * that value is a digest, so recall(cleartext) never matches. Prefer the cleartext
 * from the current request when recording a spend.
 */
#[AsDecorator(decorates: 'gesdinet_jwt_refresh_token.spent_refresh_token_registry')]
final class CleartextSpentRefreshTokenRegistry implements SpentRefreshTokenRegistryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly SpentRefreshTokenRegistryInterface $inner,
        private readonly RequestStack $requestStack,
        private readonly ExtractorInterface $extractor,
    ) {
    }

    public function remember(RefreshTokenInterface $refreshToken): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $cleartext = null !== $request
            ? $this->extractor->getRefreshToken($request, 'refresh_token')
            : null;

        if (null === $cleartext || '' === $cleartext) {
            $this->inner->remember($refreshToken);

            return;
        }

        $storedValue = $refreshToken->getRefreshToken();
        $refreshToken->setRefreshToken($cleartext);
        $this->inner->remember($refreshToken);
        if (null !== $storedValue) {
            $refreshToken->setRefreshToken($storedValue);
        }
    }

    public function recall(string $refreshToken): ?SpentRefreshToken
    {
        return $this->inner->recall($refreshToken);
    }
}

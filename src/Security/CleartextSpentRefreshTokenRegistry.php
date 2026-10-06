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
 * that value is a digest, so recall(cleartext) never matches.
 *
 * GraphQL refresh puts the accepted mutation argument on the entity before remember().
 * That cleartext is the credential. A query, cookie, or extra JSON refresh_token on the
 * same request must not replace it. REST still holds the sha256$ digest here, so the
 * request extractor supplies the cleartext for that path.
 */
#[AsDecorator(decorates: 'gesdinet_jwt_refresh_token.spent_refresh_token_registry')]
final class CleartextSpentRefreshTokenRegistry implements SpentRefreshTokenRegistryInterface
{
    private const string DIGEST_PREFIX = 'sha256$';

    public function __construct(
        #[AutowireDecorated]
        private readonly SpentRefreshTokenRegistryInterface $inner,
        private readonly RequestStack $requestStack,
        private readonly ExtractorInterface $extractor,
    ) {
    }

    public function remember(RefreshTokenInterface $refreshToken): void
    {
        $current = $refreshToken->getRefreshToken();
        if (null !== $current && '' !== $current && !str_starts_with($current, self::DIGEST_PREFIX)) {
            $this->inner->remember($refreshToken);

            return;
        }

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

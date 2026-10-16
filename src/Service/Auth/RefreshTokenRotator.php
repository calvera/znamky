<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\CleartextSpentRefreshTokenRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\FamilyAwareRefreshTokenInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Security\ReuseDetection\RefreshTokenReuseDetector;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Webmozart\Assert\Assert;

/**
 * Spends a refresh token and issues its successor in the same family (GraphQL and REST refresh).
 */
final class RefreshTokenRotator
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TokenIssuer $tokenIssuer,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly CleartextSpentRefreshTokenRegistry $spentRefreshTokens,
        private readonly RefreshTokenReuseDetector $refreshTokenReuseDetector,
    ) {
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    public function rotate(string $refreshTokenValue): array
    {
        $request = Assert::notNull(
            $this->requestStack->getCurrentRequest(),
            'Refresh requires an HTTP request.',
        );

        $stored = $this->refreshTokenManager->get($refreshTokenValue);
        if (null === $stored || !$stored->isValid()) {
            $this->refreshTokenReuseDetector->unknownTokenPresented($refreshTokenValue, $request);

            throw new UnauthorizedHttpException('Bearer', 'Invalid refresh token.');
        }

        $username = $stored->getUsername();
        if (null === $username || '' === $username) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid refresh token.');
        }

        $user = $this->userRepository->findOneByEmail($username);
        if (null === $user) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid refresh token.');
        }

        $this->tokenIssuer->assertCanAuthenticate($user);

        return $this->spend($stored, $refreshTokenValue, $user);
    }

    /**
     * Spends an already looked-up token; fails if a concurrent request spent it first.
     *
     * @return array{token: string, refresh_token: string}
     */
    public function spend(RefreshTokenInterface $stored, string $refreshTokenValue, User $user): array
    {
        $inheritedFamily = null;
        $inheritedFamilyValid = null;
        if ($stored instanceof FamilyAwareRefreshTokenInterface) {
            $inheritedFamily = $stored->getFamily();
            $inheritedFamilyValid = $stored->getFamilyValid();
        }

        return $this->entityManager->wrapInTransaction(function () use (
            $stored,
            $refreshTokenValue,
            $user,
            $inheritedFamily,
            $inheritedFamilyValid,
        ): array {
            $this->spentRefreshTokens->rememberCleartext($stored, $refreshTokenValue);
            // A concurrent rotation already deleted the row: only one caller may spend a token.
            if (1 !== $this->refreshTokenManager->delete($stored)) {
                throw new UnauthorizedHttpException('Bearer', 'Invalid refresh token.');
            }

            return $this->tokenIssuer->issue($user, $inheritedFamily, $inheritedFamilyValid);
        });
    }
}

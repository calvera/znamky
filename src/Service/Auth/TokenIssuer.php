<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Security\UserChecker;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\FamilyAwareRefreshTokenInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\Exception\AccountStatusException;

/**
 * Issues a JWT + refresh token pair for users the firewall's UserChecker accepts.
 */
final class TokenIssuer
{
    public function __construct(
        private readonly UserChecker $userChecker,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        #[Autowire('%env(int:JWT_REFRESH_TOKEN_TTL)%')]
        private readonly int $refreshTokenTtl,
    ) {
    }

    /**
     * Applies the firewall's UserChecker, reporting a blocked account as 401.
     */
    public function assertCanAuthenticate(User $user): void
    {
        try {
            $this->userChecker->checkPreAuth($user);
        } catch (AccountStatusException $e) {
            throw new UnauthorizedHttpException('Bearer', $e->getMessage(), $e);
        }
    }

    /**
     * Starts a new refresh-token family, or continues one without outliving its deadline.
     *
     * @return array{token: string, refresh_token: string}
     */
    public function issue(
        User $user,
        ?string $family = null,
        ?\DateTimeInterface $familyValid = null,
    ): array {
        $jwt = $this->jwtManager->create($user);
        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, $this->refreshTokenTtl);

        if ($refreshToken instanceof FamilyAwareRefreshTokenInterface) {
            $refreshToken->setFamily($family ?? bin2hex(random_bytes(16)));
            if (null !== $familyValid) {
                $refreshToken->setFamilyValid($familyValid);
                $this->capExpiryAt($refreshToken, $familyValid);
            }
        }

        $rawRefresh = $refreshToken->getRefreshToken();
        if (null === $rawRefresh || '' === $rawRefresh) {
            throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Failed to issue refresh token.');
        }

        $this->refreshTokenManager->save($refreshToken);

        return [
            'token' => $jwt,
            'refresh_token' => $rawRefresh,
        ];
    }

    private function capExpiryAt(RefreshTokenInterface $refreshToken, \DateTimeInterface $deadline): void
    {
        $valid = $refreshToken->getValid();
        if (null === $valid || $valid <= $deadline) {
            return;
        }

        $refreshToken->setValid(\DateTime::createFromInterface($deadline));
    }
}

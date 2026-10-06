<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\UserChecker;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\FamilyAwareRefreshTokenInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Security\ReuseDetection\RefreshTokenReuseDetector;
use Gesdinet\JWTRefreshTokenBundle\Security\ReuseDetection\SpentRefreshTokenRegistryInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\RateLimiter\DefaultLoginRateLimiter;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Webmozart\Assert\Assert;

final class AuthTokenService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserChecker $userChecker,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly SpentRefreshTokenRegistryInterface $spentRefreshTokens,
        private readonly RefreshTokenReuseDetector $refreshTokenReuseDetector,
        #[Autowire(service: 'app.login_rate_limiter')]
        private readonly DefaultLoginRateLimiter $loginRateLimiter,
        #[Autowire('%env(int:JWT_REFRESH_TOKEN_TTL)%')]
        private readonly int $refreshTokenTtl,
    ) {
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    public function login(string $email, string $password): array
    {
        $request = Assert::notNull(
            $this->requestStack->getCurrentRequest(),
            'Login requires an HTTP request.',
        );

        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $email);
        $limit = $this->loginRateLimiter->consume($request);
        if (!$limit->isAccepted()) {
            throw new UnauthorizedHttpException('Bearer', 'Too many failed login attempts, please try again later.');
        }

        $user = $this->userRepository->findOneByEmail($email);
        if (null === $user || !$this->passwordHasher->isPasswordValid($user, $password)) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid credentials.');
        }

        try {
            $this->userChecker->checkPreAuth($user);
        } catch (\Symfony\Component\Security\Core\Exception\AccountStatusException $e) {
            throw new UnauthorizedHttpException('Bearer', $e->getMessage(), $e);
        }

        $this->loginRateLimiter->reset($request);

        return $this->issueTokens($user);
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    public function refresh(string $refreshTokenValue): array
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

        try {
            $this->userChecker->checkPreAuth($user);
        } catch (\Symfony\Component\Security\Core\Exception\AccountStatusException $e) {
            throw new UnauthorizedHttpException('Bearer', $e->getMessage(), $e);
        }

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
            // Registry keys by the cleartext the client presented; hashed managers leave the
            // digest on the entity after get(), which would not match a later recall().
            $storedValue = $stored->getRefreshToken();
            $stored->setRefreshToken($refreshTokenValue);
            $this->spentRefreshTokens->remember($stored);
            if (null !== $storedValue) {
                $stored->setRefreshToken($storedValue);
            }

            $this->refreshTokenManager->delete($stored);

            return $this->issueTokens($user, $inheritedFamily, $inheritedFamilyValid);
        });
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    private function issueTokens(
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

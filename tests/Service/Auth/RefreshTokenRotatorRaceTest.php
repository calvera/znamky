<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\CleartextSpentRefreshTokenRegistry;
use App\Service\Auth\RefreshTokenRotator;
use App\Service\Auth\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Security\ReuseDetection\RefreshTokenReuseDetector;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Two workers presenting the same refresh token at once must not both get a new pair.
 *
 * The competing rotation runs in a second kernel (own DB connection and identity map),
 * squeezed between this worker's token lookup and its spend, like two PHP-FPM workers.
 */
final class RefreshTokenRotatorRaceTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager()->getConnection()
            ->executeStatement('TRUNCATE TABLE refresh_tokens, users RESTART IDENTITY CASCADE');
    }

    public function testConcurrentRotationOfOneTokenIssuesOnlyOnePair(): void
    {
        $refreshToken = $this->issueRefreshTokenFor('refresh-race@example.com');

        $competitor = fn (): array => $this->rotateInSecondWorker($refreshToken);
        $competitorResult = null;
        $rotator = $this->rotator(function () use ($competitor, &$competitorResult): void {
            $competitorResult = $competitor();
        });

        try {
            $rotator->rotate($refreshToken);
            $thisWorkerWon = true;
        } catch (UnauthorizedHttpException) {
            $thisWorkerWon = false;
        }

        self::assertIsArray($competitorResult, 'The competing rotation should have run and succeeded.');
        self::assertFalse($thisWorkerWon, 'The same refresh token was spent twice.');
        self::assertSame(1, $this->liveRefreshTokenCount());
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    private function rotateInSecondWorker(string $refreshToken): array
    {
        $thisWorker = static::$kernel;
        static::$kernel = static::createKernel();
        static::$kernel->boot();
        try {
            static::getContainer()->get(RequestStack::class)->push(Request::create('/api/graphql', 'POST'));

            return static::getContainer()->get(RefreshTokenRotator::class)->rotate($refreshToken);
        } finally {
            static::$kernel->shutdown();
            static::$kernel = $thisWorker;
        }
    }

    /**
     * Builds this worker's rotator; $afterLookup runs once, right after the token is read.
     */
    private function rotator(\Closure $afterLookup): RefreshTokenRotator
    {
        $container = static::getContainer();
        $container->get(RequestStack::class)->push(Request::create('/api/graphql', 'POST'));

        return new RefreshTokenRotator(
            $container->get(UserRepository::class),
            $container->get(TokenIssuer::class),
            new PausingRefreshTokenManager($container->get(RefreshTokenManagerInterface::class), $afterLookup),
            $container->get(RequestStack::class),
            $this->entityManager(),
            $container->get(CleartextSpentRefreshTokenRegistry::class),
            $container->get(RefreshTokenReuseDetector::class),
        );
    }

    private function issueRefreshTokenFor(string $email): string
    {
        $user = (new User())->setEmail($email)->setPassword('unused')->setIsVerified(true);
        static::getContainer()->get(UserRepository::class)->save($user);

        return static::getContainer()->get(TokenIssuer::class)->issue($user)['refresh_token'];
    }

    private function liveRefreshTokenCount(): int
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(RefreshToken::class)->count([]);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}

/**
 * Delegates to the real manager, running a callback once after the first lookup.
 */
final class PausingRefreshTokenManager implements RefreshTokenManagerInterface
{
    private bool $paused = false;

    public function __construct(
        private readonly RefreshTokenManagerInterface $inner,
        private readonly \Closure $afterLookup,
    ) {
    }

    public function get(string $refreshToken): ?RefreshTokenInterface
    {
        $token = $this->inner->get($refreshToken);
        if (!$this->paused) {
            $this->paused = true;
            ($this->afterLookup)();
        }

        return $token;
    }

    public function getLastFromUsername(string $username): ?RefreshTokenInterface
    {
        return $this->inner->getLastFromUsername($username);
    }

    public function save(RefreshTokenInterface $refreshToken): void
    {
        $this->inner->save($refreshToken);
    }

    public function delete(RefreshTokenInterface $refreshToken, bool $andFlush = true): int
    {
        return $this->inner->delete($refreshToken, $andFlush);
    }

    public function revokeAllInvalid(?\DateTimeInterface $datetime = null, bool $andFlush = true): array
    {
        return $this->inner->revokeAllInvalid($datetime, $andFlush);
    }

    public function revokeAllInvalidBatch(?\DateTimeInterface $datetime = null, ?int $batchSize = null, int $offset = 0, bool $andFlush = true): array
    {
        return $this->inner->revokeAllInvalidBatch($datetime, $batchSize, $offset, $andFlush);
    }

    public function getClass(): string
    {
        return $this->inner->getClass();
    }
}

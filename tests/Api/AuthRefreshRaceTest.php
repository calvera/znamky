<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\RefreshToken;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two workers presenting the same refresh token to POST /api/token/refresh at once must not
 * both get a new pair.
 *
 * The competing refresh runs in a second kernel (own DB connection and identity map), squeezed
 * between this worker's token lookup (authenticator) and its spend (success listener).
 */
final class AuthRefreshRaceTest extends WebTestCase
{
    use AuthApiTestTrait;

    public function testConcurrentRefreshOfOneTokenIssuesOnlyOnePair(): void
    {
        $email = 'rest-refresh-race@example.com';
        $this->registerAndVerify($email, 'password123');
        $refreshToken = $this->login($email, 'password123')['refresh_token'];

        $competitorStatus = null;
        $this->client->disableReboot();
        $this->runOnceBeforeTheTokenIsSpent(function () use ($refreshToken, &$competitorStatus): void {
            $competitorStatus = $this->refreshInSecondWorker($refreshToken);
        });

        $this->jsonRequest('POST', '/api/token/refresh', ['refresh_token' => $refreshToken]);
        $thisWorkerStatus = $this->client->getResponse()->getStatusCode();

        self::assertSame(Response::HTTP_OK, $competitorStatus, 'The competing refresh should have run and succeeded.');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $thisWorkerStatus, 'The same refresh token was spent twice.');
        self::assertSame(1, $this->entityManager()->getRepository(RefreshToken::class)->count(['username' => $email]));
    }

    /**
     * Gesdinet spends the presented token in its AUTHENTICATION_SUCCESS listener, after the
     * authenticator has already looked it up; a higher-priority listener runs in that gap.
     */
    private function runOnceBeforeTheTokenIsSpent(\Closure $competitor): void
    {
        $ran = false;
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            Events::AUTHENTICATION_SUCCESS,
            static function () use ($competitor, &$ran): void {
                if (!$ran) {
                    $ran = true;
                    $competitor();
                }
            },
            1024,
        );
    }

    private function refreshInSecondWorker(string $refreshToken): int
    {
        $kernel = static::createKernel();
        try {
            $request = Request::create(
                '/api/token/refresh',
                'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['refresh_token' => $refreshToken], \JSON_THROW_ON_ERROR),
            );

            return $kernel->handle($request)->getStatusCode();
        } finally {
            $kernel->shutdown();
        }
    }
}

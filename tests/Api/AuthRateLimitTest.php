<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AuthRateLimitTest extends WebTestCase
{
    use RateLimiterTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->purgeDatabase();
        $this->clearRateLimiters();
    }

    public function testRegisterReturns429AfterLimit(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->jsonRequest('POST', '/api/register', [
                'email' => sprintf('rate-%d@example.com', $i),
                'password' => 'password123',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED, sprintf('request %d should succeed', $i));
        }

        $this->jsonRequest('POST', '/api/register', [
            'email' => 'rate-overflow@example.com',
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }

    public function testForgotPasswordReturns429AfterLimit(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->jsonRequest('POST', '/api/forgot-password', [
                'email' => sprintf('missing-%d@example.com', $i),
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        }

        $this->jsonRequest('POST', '/api/forgot-password', [
            'email' => 'missing-overflow@example.com',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testLoginThrottlingBlocksAfterFailedAttempts(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->jsonRequest('POST', '/api/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong-password',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }

        $this->jsonRequest('POST', '/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $body = $this->client->getResponse()->getContent() ?: '';
        self::assertStringContainsStringIgnoringCase('too many', $body);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(string $method, string $uri, array $payload = []): void
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function purgeDatabase(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $connection = $em->getConnection();
        $connection->executeStatement('TRUNCATE TABLE refresh_tokens, users RESTART IDENTITY CASCADE');
        $em->clear();
    }
}

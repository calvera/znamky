<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AuthRateLimitTest extends WebTestCase
{
    use EmailTokenTestTrait;
    use MailerAssertionsTrait;
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
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED, sprintf('request %d should succeed', $i));
        }

        $this->jsonRequest('POST', '/api/register', [
            'email' => 'rate-overflow@example.com',
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

    public function testSuccessfulLoginResetsTheUsernameLimit(): void
    {
        $email = 'reset-limit@example.com';
        $password = 'password123';
        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $this->extractTokenFromLastEmail(),
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->jsonRequest('POST', '/api/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
            self::assertStringNotContainsStringIgnoringCase('too many', $this->client->getResponse()->getContent() ?: '');
        }

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseIsSuccessful();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->jsonRequest('POST', '/api/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
            self::assertStringNotContainsStringIgnoringCase('too many', $this->client->getResponse()->getContent() ?: '');
        }

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertStringContainsStringIgnoringCase('too many', $this->client->getResponse()->getContent() ?: '');
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

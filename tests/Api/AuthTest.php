<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AuthTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->purgeDatabase();
    }

    public function testRegisterVerifyLoginAndMe(): void
    {
        $email = 'user@example.com';
        $password = 'password123';

        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertEmailCount(1);
        $verificationToken = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $verificationToken,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $tokens = $this->login($email, $password);
        self::assertArrayHasKey('token', $tokens);
        self::assertArrayHasKey('refresh_token', $tokens);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token'],
        ]);
        self::assertResponseIsSuccessful();
        $me = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($email, $me['email']);
        self::assertContains('ROLE_USER', $me['roles']);
    }

    public function testRegisterDuplicateEmailReturns422(): void
    {
        $payload = ['email' => 'dup@example.com', 'password' => 'password123'];
        $this->jsonRequest('POST', '/api/register', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->jsonRequest('POST', '/api/register', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRegisterInvalidPayloadReturnsStructured422(): void
    {
        $this->jsonRequest('POST', '/api/register', [
            'email' => 'not-an-email',
            'password' => 'short',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $body = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Validation Failed', $body['title'] ?? null);
        self::assertSame('The given data failed validation.', $body['detail'] ?? null);
        self::assertIsArray($body['violations'] ?? null);
        self::assertNotEmpty($body['violations']);
        self::assertArrayHasKey('propertyPath', $body['violations'][0]);
        self::assertArrayHasKey('message', $body['violations'][0]);
    }

    public function testRefreshRotatesToken(): void
    {
        $email = 'refresh@example.com';
        $password = 'password123';
        $this->registerAndVerify($email, $password);
        $tokens = $this->login($email, $password);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
        $newTokens = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);
        self::assertNotSame($tokens['refresh_token'], $newTokens['refresh_token']);
        self::assertNotEmpty($newTokens['token']);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLogoutBlocksAccessAndRefresh(): void
    {
        $email = 'logout@example.com';
        $password = 'password123';
        $this->registerAndVerify($email, $password);
        $tokens = $this->login($email, $password);

        $this->client->request(
            'POST',
            '/api/logout',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token'],
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['refresh_token' => $tokens['refresh_token']], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testForgotAndResetPassword(): void
    {
        $email = 'reset@example.com';
        $oldPassword = 'password123';
        $newPassword = 'newpassword456';
        $this->registerAndVerify($email, $oldPassword);
        $tokens = $this->login($email, $oldPassword);

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertEmailCount(1);
        $resetToken = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $resetToken,
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $oldPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->login($email, $newPassword);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testForgotUnknownEmailStillReturns204(): void
    {
        $this->jsonRequest('POST', '/api/forgot-password', ['email' => 'nobody@example.com']);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertEmailCount(0);
    }

    public function testInvalidVerificationTokenReturns422(): void
    {
        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => 'invalid-token',
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testVerifyReplacesPasswordFromRegistration(): void
    {
        $email = 'victim@example.com';
        $registrationPassword = 'attacker-password';
        $ownerPassword = 'owner-password-1';

        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $registrationPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => $ownerPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $registrationPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->login($email, $ownerPassword);
    }

    public function testVerifyWithoutPasswordLeavesAccountUnverified(): void
    {
        $email = 'pending@example.com';
        $password = 'password123';

        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/verify-email', ['token' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMeWithoutJwtReturns401(): void
    {
        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
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

    /**
     * @return array{token: string, refresh_token: string}
     */
    private function login(string $email, string $password): array
    {
        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseIsSuccessful();

        /** @var array{token: string, refresh_token: string} $tokens */
        $tokens = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);

        return $tokens;
    }

    private function registerAndVerify(string $email, string $password): void
    {
        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertEmailCount(1);

        $token = $this->extractTokenFromLastEmail();
        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    private function extractTokenFromLastEmail(): string
    {
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        $body = method_exists($email, 'getHtmlBody') ? ($email->getHtmlBody() ?: '') : (string) $email;
        if ('' === $body && method_exists($email, 'toString')) {
            $body = $email->toString();
        }
        self::assertMatchesRegularExpression('/([a-f0-9]{64})/', $body);
        preg_match('/([a-f0-9]{64})/', $body, $matches);

        return $matches[1];
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

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AuthTest extends WebTestCase
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

    public function testLoginAcceptsEmailCasingUsedAtRegistration(): void
    {
        $email = 'Alice.Smith@Example.com';
        $password = 'password123';
        $this->registerAndVerify($email, $password);

        $tokens = $this->login($email, $password);
        self::assertNotEmpty($tokens['token']);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token'],
        ]);
        self::assertResponseIsSuccessful();
        $me = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(strtolower($email), $me['email']);

        $this->login(strtolower($email), $password);

        $this->jsonRequest('POST', '/api/register', [
            'email' => strtolower($email),
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
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

    public function testRegisterStoresLowercaseEmailAndRejectsCaseVariantDuplicate(): void
    {
        $this->jsonRequest('POST', '/api/register', [
            'email' => 'User@Example.com',
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('user@example.com', $created['email'] ?? null);
        $token = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/register', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $tokens = $this->login('user@example.com', 'password123');
        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token'],
        ]);
        self::assertResponseIsSuccessful();
        $me = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('user@example.com', $me['email']);
    }

    public function testExpiredVerificationTokenDoesNotVerifyUser(): void
    {
        $email = 'expired-verify@example.com';
        $password = 'password123';
        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->extractTokenFromLastEmail();
        $this->expireUserToken($email, 'emailVerificationTokenExpiresAt');

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testVerificationTokenCannotBeReused(): void
    {
        $email = 'reuse-verify@example.com';
        $password = 'password123';
        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->login($email, $password);
    }

    public function testInvalidPasswordResetTokenReturns422(): void
    {
        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => 'not-a-real-token',
            'password' => 'newpassword456',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testExpiredPasswordResetTokenKeepsOldPassword(): void
    {
        $email = 'expired-reset@example.com';
        $oldPassword = 'password123';
        $newPassword = 'newpassword456';
        $this->registerAndVerify($email, $oldPassword);

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $token = $this->extractTokenFromLastEmail();
        $this->expireUserToken($email, 'passwordResetTokenExpiresAt');

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $token,
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->login($email, $oldPassword);
    }

    public function testLogoutWithoutRefreshTokenRevokesEverySession(): void
    {
        $email = 'logout-all@example.com';
        $password = 'password123';
        $this->registerAndVerify($email, $password);
        $first = $this->login($email, $password);
        $second = $this->login($email, $password);

        $this->client->request(
            'POST',
            '/api/logout',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$first['token'],
                'CONTENT_TYPE' => 'application/json',
            ],
            content: '{}',
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$first['token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $first['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $second['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPasswordResetTokenCannotBeReused(): void
    {
        $email = 'reuse-reset@example.com';
        $oldPassword = 'password123';
        $newPassword = 'newpassword456';
        $otherPassword = 'otherpassword789';
        $this->registerAndVerify($email, $oldPassword);

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $token = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $token,
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $token,
            'password' => $otherPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $otherPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->login($email, $newPassword);
    }

    public function testNewerPasswordResetRequestInvalidatesPreviousToken(): void
    {
        $email = 'rotate-reset@example.com';
        $oldPassword = 'password123';
        $newPassword = 'newpassword456';
        $this->registerAndVerify($email, $oldPassword);

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $firstToken = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $secondToken = $this->extractTokenFromLastEmail();
        self::assertNotSame($firstToken, $secondToken);

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $firstToken,
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->login($email, $oldPassword);

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $secondToken,
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->login($email, $newPassword);
    }

    public function testForgotPasswordMatchesStoredEmailRegardlessOfCase(): void
    {
        $oldPassword = 'password123';
        $newPassword = 'newpassword456';
        $this->registerAndVerify('Alice.Reset@Example.com', $oldPassword);

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => 'alice.reset@example.com']);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertEmailCount(1);

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $this->extractTokenFromLastEmail(),
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->login('ALICE.RESET@example.com', $newPassword);
    }

    public function testShortResetPasswordDoesNotConsumeTheToken(): void
    {
        $email = 'short-reset@example.com';
        $oldPassword = 'password123';
        $newPassword = 'newpassword456';
        $this->registerAndVerify($email, $oldPassword);

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $token = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $token,
            'password' => 'short',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);
        $paths = array_column($body['violations'] ?? [], 'propertyPath');
        self::assertContains('password', $paths);

        $this->login($email, $oldPassword);

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $token,
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->login($email, $newPassword);
    }

    public function testPasswordResetDoesNotVerifyUnverifiedAccount(): void
    {
        $email = 'unverified-reset@example.com';
        $oldPassword = 'password123';
        $newPassword = 'newpassword456';

        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $oldPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $verificationToken = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $resetToken = $this->extractTokenFromLastEmail();
        self::assertNotSame($verificationToken, $resetToken);

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $resetToken,
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $oldPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $verificationToken,
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->login($email, $newPassword);
    }

    public function testVerifyAfterResetUsesPasswordChosenAtVerification(): void
    {
        $email = 'verify-after-reset@example.com';
        $registrationPassword = 'password123';
        $resetPassword = 'resetpassword1';
        $verificationPassword = 'chosen-by-inbox';

        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $registrationPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $verificationToken = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $resetToken = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $resetToken,
            'password' => $resetPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $resetPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $verificationToken,
            'password' => $verificationPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $resetPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $registrationPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->login($email, $verificationPassword);
    }

    public function testPasswordResetRevokesEveryRefreshSessionButNotOtherAccounts(): void
    {
        $email = 'reset-sessions@example.com';
        $this->registerAndVerify($email, 'password123');
        $first = $this->login($email, 'password123');
        $second = $this->login($email, 'password123');

        $this->registerAndVerify('bystander@example.com', 'password123');
        $bystander = $this->login('bystander@example.com', 'password123');

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $this->extractTokenFromLastEmail(),
            'password' => 'newpassword456',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $first['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $second['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $bystander['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();

        $this->login($email, 'newpassword456');
    }

    public function testShortVerificationPasswordDoesNotConsumeTheToken(): void
    {
        $email = 'short-verify@example.com';
        $password = 'password123';
        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => 'short',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);
        $paths = array_column($body['violations'] ?? [], 'propertyPath');
        self::assertContains('password', $paths);

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->login($email, $password);
    }

    public function testIssuedAuthTokensExpireOnTheirExpectedWindows(): void
    {
        $email = 'token-lifetime@example.com';
        $registeredAt = new \DateTimeImmutable();
        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $verificationExpires = $this->findUser($email)->getEmailVerificationTokenExpiresAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $verificationExpires);
        self::assertGreaterThan($registeredAt->modify('+23 hours'), $verificationExpires);
        self::assertLessThan($registeredAt->modify('+25 hours'), $verificationExpires);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $this->extractTokenFromLastEmail(),
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $requestedAt = new \DateTimeImmutable();
        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $user = $this->findUser($email);
        $resetExpires = $user->getPasswordResetTokenExpiresAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $resetExpires);
        self::assertGreaterThan($requestedAt->modify('+50 minutes'), $resetExpires);
        self::assertLessThan($requestedAt->modify('+70 minutes'), $resetExpires);
        self::assertNull($user->getEmailVerificationToken());
    }

    public function testLogoutWithEmptyRefreshTokenRevokesEverySession(): void
    {
        $email = 'logout-empty@example.com';
        $password = 'password123';
        $this->registerAndVerify($email, $password);
        $first = $this->login($email, $password);
        $second = $this->login($email, $password);

        $this->client->request(
            'POST',
            '/api/logout',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$first['token'],
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['refresh_token' => ''], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $first['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $second['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLogoutWithForeignRefreshTokenDoesNotRevokeOtherSession(): void
    {
        $this->registerAndVerify('owner@example.com', 'password123');
        $ownerFirst = $this->login('owner@example.com', 'password123');
        $ownerSecond = $this->login('owner@example.com', 'password123');

        $this->jsonRequest('POST', '/api/register', [
            'email' => 'other@example.com',
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $this->extractTokenFromLastEmail(),
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $other = $this->login('other@example.com', 'password123');

        $this->client->request(
            'POST',
            '/api/logout',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$ownerFirst['token'],
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['refresh_token' => $other['refresh_token']], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$ownerFirst['token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $other['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $ownerSecond['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
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

    private function findUser(string $email): User
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => strtolower($email)]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function expireUserToken(string $email, string $field): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $this->findUser($email);

        if ('emailVerificationTokenExpiresAt' === $field) {
            $user->setEmailVerificationTokenExpiresAt(new \DateTimeImmutable('-1 minute'));
        } else {
            $user->setPasswordResetTokenExpiresAt(new \DateTimeImmutable('-1 minute'));
        }

        $em->flush();
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

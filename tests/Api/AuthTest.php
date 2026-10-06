<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\AuthMailer;
use App\Service\Auth\PasswordResetService;
use App\Service\Auth\TokenHasher;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RevokeRefreshTokenManagerInterface;
use Safe\DateTime;
use Safe\DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthTest extends WebTestCase
{
    use EmailTokenTestTrait;
    use JsonResponseTestTrait;
    use MailerAssertionsTrait;
    use RateLimiterTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->purgeDatabase();
        $this->clearRateLimiters();
    }

    public function testRegisterDoesNotKeepTheAccountWhenVerificationEmailFails(): void
    {
        $this->installThrowingMailer();

        $this->jsonRequest('POST', '/api/register', [
            'email' => 'smtp-down@example.com',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);

        // A new PHP request must not find an account that never received its token.
        static::ensureKernelShutdown();
        $this->client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        self::assertNull($em->getRepository(User::class)->findOneBy(['email' => 'smtp-down@example.com']));

        $this->jsonRequest('POST', '/api/register', [
            'email' => 'smtp-down@example.com',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $this->extractTokenFromLastEmail(),
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->login('smtp-down@example.com', 'password123');
    }

    public function testFailedPasswordResetEmailDoesNotInvalidateThePreviousToken(): void
    {
        $email = 'reset-smtp@example.com';
        $this->registerAndVerify($email, 'password123');

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $token = $this->extractTokenFromLastEmail();

        $reset = new PasswordResetService(
            static::getContainer()->get(UserRepository::class),
            static::getContainer()->get(EntityManagerInterface::class),
            static::getContainer()->get(TokenHasher::class),
            new AuthMailer(new class implements MailerInterface {
                public function send(RawMessage $message, ?Envelope $envelope = null): void
                {
                    throw new TransportException('SMTP down');
                }
            }, 'noreply@znamky.local'),
            static::getContainer()->get(UserPasswordHasherInterface::class),
            static::getContainer()->get(RevokeRefreshTokenManagerInterface::class),
        );

        try {
            $reset->requestReset($email);
            self::fail('A mail transport failure was expected.');
        } catch (TransportException) {
        }

        static::ensureKernelShutdown();
        $this->client = static::createClient();

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $token,
            'password' => 'newpassword456',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->login($email, 'newpassword456');
    }

    public function testRegisterVerifyLoginAndMe(): void
    {
        $email = 'user@example.com';
        $password = 'password123';

        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
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

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token'],
        ]);
        self::assertResponseIsSuccessful();
        $me = $this->jsonResponse();
        self::assertSame($email, $me['email']);
        $roles = $me['roles'] ?? null;
        if (!\is_array($roles)) {
            self::fail('Expected roles array.');
        }
        self::assertContains('ROLE_USER', $roles);
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
        $me = $this->jsonResponse();
        self::assertSame(strtolower($email), $me['email']);

        $this->login(strtolower($email), $password);

        $this->jsonRequest('POST', '/api/register', [
            'email' => strtolower($email),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRegisterDuplicateEmailReturns422(): void
    {
        $payload = ['email' => 'dup@example.com'];
        $this->jsonRequest('POST', '/api/register', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->extractTokenFromLastEmail();
        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/register', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRegisterInvalidPayloadReturnsStructured422(): void
    {
        $this->jsonRequest('POST', '/api/register', [
            'email' => 'not-an-email',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $body = $this->jsonResponse();
        self::assertSame('Validation Failed', $body['title'] ?? null);
        self::assertSame('The given data failed validation.', $body['detail'] ?? null);
        $violations = $body['violations'] ?? null;
        if (!\is_array($violations) || [] === $violations) {
            self::fail('Expected non-empty violations array.');
        }
        $firstViolation = $violations[0] ?? null;
        if (!\is_array($firstViolation)) {
            self::fail('Expected first violation to be an array.');
        }
        self::assertArrayHasKey('propertyPath', $firstViolation);
        self::assertArrayHasKey('message', $firstViolation);
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
        $newTokens = $this->jsonResponse();
        self::assertNotSame($tokens['refresh_token'], $newTokens['refresh_token']);
        self::assertNotEmpty($newTokens['token']);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRefreshReuseRevokesTheTokenFamily(): void
    {
        $this->registerAndVerify('refresh-reuse@example.com', 'password123');
        $tokens = $this->login('refresh-reuse@example.com', 'password123');

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
        $rotated = $this->jsonResponse();
        $newRefresh = $rotated['refresh_token'] ?? null;
        self::assertIsString($newRefresh);
        self::assertNotSame($tokens['refresh_token'], $newRefresh);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $newRefresh,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testStoredRefreshTokenDigestCannotBeUsedToRefresh(): void
    {
        $email = 'digest-refresh@example.com';
        $this->registerAndVerify($email, 'password123');
        $tokens = $this->login($email, 'password123');

        $stored = $this->storedRefreshTokenFor($email);
        self::assertNotSame($tokens['refresh_token'], $stored);
        self::assertStringStartsWith('sha256$', $stored);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $stored,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testCleartextRefreshTokenRowCannotBeUsedToRefresh(): void
    {
        $email = 'cleartext-refresh@example.com';
        $this->registerAndVerify($email, 'password123');
        $cleartext = bin2hex(random_bytes(64));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $refreshToken = new RefreshToken();
        $refreshToken->setRefreshToken($cleartext);
        $refreshToken->setUsername($email);
        $refreshToken->setValid(new DateTime('+1 day'));
        $em->persist($refreshToken);
        $em->flush();

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $cleartext,
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

    public function testStaleBearerDoesNotBlockPublicRegister(): void
    {
        $email = 'stale-bearer@example.com';
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

        $this->client->request(
            'POST',
            '/api/register',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token'],
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['email' => 'stale-bearer-register@example.com'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token'],
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

    public function testVerifySetsAccountPassword(): void
    {
        $email = 'victim@example.com';
        $ownerPassword = 'owner-password-1';

        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $ownerPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => $ownerPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->login($email, $ownerPassword);
    }

    public function testRegisterIgnoresAPasswordInTheBody(): void
    {
        $email = 'leftover-password@example.com';
        $registrationPassword = 'attacker-password';
        $ownerPassword = 'owner-password-1';

        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $registrationPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $verificationToken = $this->extractTokenFromLastEmail();

        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('', $user->getPassword());
        self::assertFalse($user->isVerified());

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $registrationPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $verificationToken,
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

    public function testRegisterStoresLowercaseEmailAndRejectsVerifiedDuplicate(): void
    {
        $this->jsonRequest('POST', '/api/register', [
            'email' => 'User@Example.com',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->jsonResponse();
        self::assertSame('user@example.com', $created['email'] ?? null);
        $token = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => 'password123',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/register', [
            'email' => 'user@example.com',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $tokens = $this->login('user@example.com', 'password123');
        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token'],
        ]);
        self::assertResponseIsSuccessful();
        $me = $this->jsonResponse();
        self::assertSame('user@example.com', $me['email']);
    }

    public function testExpiredVerificationCanBeReclaimedByRegisteringAgain(): void
    {
        $email = 'reclaim-verify@example.com';
        $password = 'password123';

        $this->jsonRequest('POST', '/api/register', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $oldToken = $this->extractTokenFromLastEmail();
        $this->expireUserToken($email, 'emailVerificationTokenExpiresAt');

        $this->jsonRequest('POST', '/api/register', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertEmailCount(1);
        $newToken = $this->extractTokenFromLastEmail();
        self::assertNotSame($oldToken, $newToken);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $oldToken,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $newToken,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->login($email, $password);
    }

    public function testExpiredVerificationTokenDoesNotVerifyUser(): void
    {
        $email = 'expired-verify@example.com';
        $password = 'password123';
        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
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
        $body = $this->jsonResponse();
        $violations = $body['violations'] ?? null;
        if (!\is_array($violations)) {
            self::fail('Expected violations array.');
        }
        $paths = array_column($violations, 'propertyPath');
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
        $newPassword = 'newpassword456';

        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
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

        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        self::assertFalse($user->isVerified());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $newPassword));

        $this->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $message = $this->jsonResponse()['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('Please verify your email', $message);

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
        $resetPassword = 'resetpassword1';
        $verificationPassword = 'chosen-by-inbox';

        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
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
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => 'short',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = $this->jsonResponse();
        $violations = $body['violations'] ?? null;
        if (!\is_array($violations)) {
            self::fail('Expected violations array.');
        }
        $paths = array_column($violations, 'propertyPath');
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
        $registeredAt = new DateTimeImmutable();
        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
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

        $requestedAt = new DateTimeImmutable();
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

    private function installThrowingMailer(): void
    {
        static::getContainer()->set(MailerInterface::class, new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('SMTP down');
            }
        });
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

        $tokens = $this->jsonResponse();
        $token = $tokens['token'] ?? null;
        $refreshToken = $tokens['refresh_token'] ?? null;
        if (!\is_string($token) || !\is_string($refreshToken)) {
            self::fail('Expected login response with token and refresh_token strings.');
        }

        return ['token' => $token, 'refresh_token' => $refreshToken];
    }

    private function registerAndVerify(string $email, string $password): void
    {
        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
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

    private function storedRefreshTokenFor(string $email): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $stored = $em->getConnection()->fetchOne(
            'SELECT refresh_token FROM refresh_tokens WHERE username = :username',
            ['username' => strtolower($email)],
        );
        self::assertIsString($stored);

        return $stored;
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
            $user->setEmailVerificationTokenExpiresAt(new DateTimeImmutable('-1 minute'));
        } else {
            $user->setPasswordResetTokenExpiresAt(new DateTimeImmutable('-1 minute'));
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

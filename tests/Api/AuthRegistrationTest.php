<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Safe\DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

final class AuthRegistrationTest extends WebTestCase
{
    use AuthApiTestTrait;

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
            'password' => 'Blue-Otter-Canoe-77',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->login('smtp-down@example.com', 'Blue-Otter-Canoe-77');
    }

    public function testRegisterVerifyLoginAndMe(): void
    {
        $email = 'user@example.com';
        $password = 'Blue-Otter-Canoe-77';

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

    public function testRegisterEmailDefaultsToEnglish(): void
    {
        $this->jsonRequest('POST', '/api/register', [
            'email' => 'locale-en@example.com',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Verify your email', $email->getSubject());
        $html = $email->getHtmlBody();
        self::assertIsString($html);
        self::assertStringContainsString('lang="en"', $html);
        self::assertStringContainsString('Verification code', $html);
        $token = $this->extractTokenFromLastEmail();
        self::assertStringContainsString($token, $html);
        $text = $email->getTextBody();
        self::assertIsString($text);
        self::assertStringContainsString('Verification code', $text);
        self::assertStringContainsString('This code expires in 24 hours.', $text);
        self::assertStringContainsString($token, $text);
    }

    public function testRegisterEmailUsesCzechLocale(): void
    {
        $this->jsonRequest('POST', '/api/register', [
            'email' => 'locale-cs@example.com',
            'locale' => 'cs',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Ověření e-mailu', $email->getSubject());
        $html = $email->getHtmlBody();
        self::assertIsString($html);
        self::assertStringContainsString('lang="cs"', $html);
        self::assertStringContainsString('Ověřovací kód', $html);
        $token = $this->extractTokenFromLastEmail();
        self::assertStringContainsString($token, $html);
        $text = $email->getTextBody();
        self::assertIsString($text);
        self::assertStringContainsString('Ověřovací kód', $text);
        self::assertStringContainsString('Tento kód vyprší za 24 hodin.', $text);
        self::assertStringContainsString($token, $text);
    }

    public function testRegisterInvalidLocaleReturns422(): void
    {
        $this->jsonRequest('POST', '/api/register', [
            'email' => 'locale-bad@example.com',
            'locale' => 'de',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertEmailCount(0);
    }

    public function testReregisteringUnverifiedAccountSendsTheRequestedLocale(): void
    {
        $emailAddress = 'reissue-locale-cs@example.com';

        $this->jsonRequest('POST', '/api/register', [
            'email' => $emailAddress,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $first);
        self::assertSame('Verify your email', $first->getSubject());
        $oldToken = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/register', [
            'email' => $emailAddress,
            'locale' => 'cs',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Ověření e-mailu', $email->getSubject());
        $html = $email->getHtmlBody();
        self::assertIsString($html);
        self::assertStringContainsString('lang="cs"', $html);
        $newToken = $this->extractTokenFromLastEmail();
        self::assertNotSame($oldToken, $newToken);
        self::assertStringContainsString($newToken, $html);
        $text = $email->getTextBody();
        self::assertIsString($text);
        self::assertStringContainsString($newToken, $text);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $oldToken,
            'password' => 'Blue-Otter-Canoe-77',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $newToken,
            'password' => 'Blue-Otter-Canoe-77',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->login($emailAddress, 'Blue-Otter-Canoe-77');
    }

    public function testRegisterDuplicateEmailReturns422(): void
    {
        $payload = ['email' => 'dup@example.com'];
        $this->jsonRequest('POST', '/api/register', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->extractTokenFromLastEmail();
        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => 'Blue-Otter-Canoe-77',
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

    public function testStaleBearerDoesNotBlockPublicRegister(): void
    {
        $email = 'stale-bearer@example.com';
        $password = 'Blue-Otter-Canoe-77';
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

    public function testInvalidVerificationTokenReturns422(): void
    {
        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => 'invalid-token',
            'password' => 'Blue-Otter-Canoe-77',
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
        $password = 'Blue-Otter-Canoe-77';

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
            'password' => 'Blue-Otter-Canoe-77',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->jsonRequest('POST', '/api/register', [
            'email' => 'user@example.com',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $tokens = $this->login('user@example.com', 'Blue-Otter-Canoe-77');
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
        $password = 'Blue-Otter-Canoe-77';

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

    public function testReregisteringUnverifiedAccountRotatesTheLiveToken(): void
    {
        $email = 'reclaim-live@example.com';
        $password = 'Blue-Otter-Canoe-77';

        $this->jsonRequest('POST', '/api/register', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $oldToken = $this->extractTokenFromLastEmail();

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

    public function testFailedReissueEmailKeepsThePreviousVerificationToken(): void
    {
        $email = 'reissue-smtp@example.com';
        $password = 'Blue-Otter-Canoe-77';

        $this->jsonRequest('POST', '/api/register', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->extractTokenFromLastEmail();

        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->installThrowingMailer();

        $this->jsonRequest('POST', '/api/register', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);

        static::ensureKernelShutdown();
        $this->client = static::createClient();

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->login($email, $password);
    }

    public function testExpiredVerificationTokenDoesNotVerifyUser(): void
    {
        $email = 'expired-verify@example.com';
        $password = 'Blue-Otter-Canoe-77';
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
        $password = 'Blue-Otter-Canoe-77';
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

    public function testVerificationTokenExpiresByTheApplicationClock(): void
    {
        $email = 'clock-verify@example.com';
        $this->jsonRequest('POST', '/api/register', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        self::mockTime('+25 hours');
        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $this->extractTokenFromLastEmail(),
            'password' => 'Blue-Otter-Canoe-77',
        ]);
        $this->assertJsonError(Response::HTTP_UNPROCESSABLE_ENTITY, 'Verification token has expired.');
    }

    public function testInvalidVerificationTokenReturnsJsonError(): void
    {
        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => 'invalid-token',
            'password' => 'Blue-Otter-Canoe-77',
        ]);
        $this->assertJsonError(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid verification token.');
    }

    public function testExpiredVerificationTokenReturnsJsonError(): void
    {
        $email = 'json-expired-verify@example.com';
        $this->jsonRequest('POST', '/api/register', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->extractTokenFromLastEmail();
        $this->expireUserToken($email, 'emailVerificationTokenExpiresAt');

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => 'Blue-Otter-Canoe-77',
        ]);
        $this->assertJsonError(Response::HTTP_UNPROCESSABLE_ENTITY, 'Verification token has expired.');
    }

    public function testWeakVerificationPasswordIsRejected(): void
    {
        $this->jsonRequest('POST', '/api/register', ['email' => 'weak-verify@example.com']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $this->extractTokenFromLastEmail(),
            'password' => 'qwerty12345',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $violations = $this->jsonResponse()['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertContains('password', array_column($violations, 'propertyPath'));
    }

    public function testShortVerificationPasswordDoesNotConsumeTheToken(): void
    {
        $email = 'short-verify@example.com';
        $password = 'Blue-Otter-Canoe-77';
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

    public function testVerificationTokenWithoutExpiryDoesNotVerifyOrGetConsumed(): void
    {
        $email = 'null-expiry-verify@example.com';
        $password = 'Blue-Otter-Canoe-77';
        $this->jsonRequest('POST', '/api/register', [
            'email' => $email,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $token = $this->extractTokenFromLastEmail();

        $user = $this->findUser($email);
        $user->setEmailVerificationTokenExpiresAt(null);
        $this->entityManager()->flush();

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertFalse($this->findUser($email)->isVerified());

        $user = $this->findUser($email);
        $user->setEmailVerificationTokenExpiresAt(new DateTimeImmutable('+1 day'));
        $this->entityManager()->flush();

        $this->jsonRequest('POST', '/api/verify-email', [
            'token' => $token,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->login($email, $password);
    }
}

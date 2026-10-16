<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\AuthMailer;
use App\Service\Auth\EmailTokenLookup;
use App\Service\Auth\PasswordResetService;
use App\Service\Auth\TokenHasher;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RevokeRefreshTokenManagerInterface;
use Safe\DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AuthPasswordResetTest extends WebTestCase
{
    use AuthApiTestTrait;

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
            static::getContainer()->get(EmailTokenLookup::class),
            new AuthMailer(new class implements MailerInterface {
                public function send(RawMessage $message, ?Envelope $envelope = null): void
                {
                    throw new TransportException('SMTP down');
                }
            }, static::getContainer()->get(TranslatorInterface::class), 'noreply@znamky.local'),
            static::getContainer()->get(UserPasswordHasherInterface::class),
            static::getContainer()->get(RevokeRefreshTokenManagerInterface::class),
            new Clock(),
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

    public function testForgotPasswordEmailUsesCzechLocale(): void
    {
        $emailAddress = 'reset-locale-cs@example.com';
        $this->registerAndVerify($emailAddress, 'password123');

        $this->jsonRequest('POST', '/api/forgot-password', [
            'email' => $emailAddress,
            'locale' => 'cs',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Obnovení hesla', $email->getSubject());
        $html = $email->getHtmlBody();
        self::assertIsString($html);
        self::assertStringContainsString('lang="cs"', $html);
        self::assertStringContainsString('Kód pro obnovení', $html);
        $token = $this->extractTokenFromLastEmail();
        self::assertStringContainsString($token, $html);
        $text = $email->getTextBody();
        self::assertIsString($text);
        self::assertStringContainsString('Kód pro obnovení', $text);
        self::assertStringContainsString('Tento kód vyprší za 1 hodinu.', $text);
        self::assertStringContainsString($token, $text);
    }

    public function testForgotPasswordEmailDefaultsToEnglish(): void
    {
        $emailAddress = 'reset-locale-en@example.com';
        $this->registerAndVerify($emailAddress, 'password123');

        $this->jsonRequest('POST', '/api/forgot-password', [
            'email' => $emailAddress,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Reset your password', $email->getSubject());
        $html = $email->getHtmlBody();
        self::assertIsString($html);
        self::assertStringContainsString('lang="en"', $html);
        self::assertStringContainsString('Reset code', $html);
        $token = $this->extractTokenFromLastEmail();
        self::assertStringContainsString($token, $html);
        $text = $email->getTextBody();
        self::assertIsString($text);
        self::assertStringContainsString('Reset code', $text);
        self::assertStringContainsString('This code expires in 1 hour.', $text);
        self::assertStringContainsString($token, $text);
    }

    public function testForgotPasswordInvalidLocaleDoesNotReplaceTheResetToken(): void
    {
        $emailAddress = 'reset-locale-keep@example.com';
        $this->registerAndVerify($emailAddress, 'password123');

        $this->jsonRequest('POST', '/api/forgot-password', [
            'email' => $emailAddress,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $token = $this->extractTokenFromLastEmail();

        $this->jsonRequest('POST', '/api/forgot-password', [
            'email' => $emailAddress,
            'locale' => 'de',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertEmailCount(0);

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $token,
            'password' => 'newpassword456',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->login($emailAddress, 'newpassword456');
    }

    public function testForgotPasswordInvalidLocaleReturns422(): void
    {
        $this->jsonRequest('POST', '/api/forgot-password', [
            'email' => 'nobody@example.com',
            'locale' => 'fr',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
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

    public function testPasswordResetTokenExpiresByTheApplicationClock(): void
    {
        $email = 'clock-reset@example.com';
        $oldPassword = 'password123';
        $this->registerAndVerify($email, $oldPassword);

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        self::mockTime('+61 minutes');
        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $this->extractTokenFromLastEmail(),
            'password' => 'newpassword456',
        ]);
        $this->assertJsonError(Response::HTTP_UNPROCESSABLE_ENTITY, 'Password reset token has expired.');

        $this->login($email, $oldPassword);
    }

    public function testInvalidPasswordResetTokenReturnsJsonError(): void
    {
        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => 'invalid-token',
            'password' => 'newpassword456',
        ]);
        $this->assertJsonError(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid password reset token.');
    }

    public function testExpiredPasswordResetTokenReturnsJsonError(): void
    {
        $email = 'json-expired-reset@example.com';
        $this->registerAndVerify($email, 'password123');
        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $token = $this->extractTokenFromLastEmail();
        $this->expireUserToken($email, 'passwordResetTokenExpiresAt');

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $token,
            'password' => 'newpassword456',
        ]);
        $this->assertJsonError(Response::HTTP_UNPROCESSABLE_ENTITY, 'Password reset token has expired.');
    }

    public function testExpiredPasswordResetDoesNotRevokeOutstandingAccessTokens(): void
    {
        $email = 'expired-reset-session@example.com';
        $password = 'password123';
        $this->registerAndVerify($email, $password);
        $tokens = $this->login($email, $password);

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $token = $this->extractTokenFromLastEmail();
        $this->expireUserToken($email, 'passwordResetTokenExpiresAt');

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $token,
            'password' => 'newpassword456',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token'],
        ]);
        self::assertResponseIsSuccessful();

        $this->jsonRequest('POST', '/api/token/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
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

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$first['token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$second['token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$bystander['token'],
        ]);
        self::assertResponseIsSuccessful();

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

        // Revocation marks compare iat at second resolution and also refuse a token
        // issued in the same second, so wait out that second before logging in again.
        $this->waitForNextSecond();
        $fresh = $this->login($email, 'newpassword456');
        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$fresh['token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testPasswordResetTokenWithoutExpiryKeepsTheOldPassword(): void
    {
        $email = 'null-expiry-reset@example.com';
        $oldPassword = 'password123';
        $newPassword = 'newpassword456';
        $this->registerAndVerify($email, $oldPassword);

        $this->jsonRequest('POST', '/api/forgot-password', ['email' => $email]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $token = $this->extractTokenFromLastEmail();

        $user = $this->findUser($email);
        $user->setPasswordResetTokenExpiresAt(null);
        $this->entityManager()->flush();

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $token,
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->login($email, $oldPassword);

        $user = $this->findUser($email);
        $user->setPasswordResetTokenExpiresAt(new DateTimeImmutable('+1 hour'));
        $this->entityManager()->flush();

        $this->jsonRequest('POST', '/api/reset-password', [
            'token' => $token,
            'password' => $newPassword,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->login($email, $newPassword);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

final class AuthGraphQlPasswordResetTest extends WebTestCase
{
    use AuthGraphQlTestTrait;

    public function testForgotPasswordEmailUsesCzechLocale(): void
    {
        $emailAddress = 'graphql-reset-locale-cs@example.com';
        $this->registerVerifyAndLogin($emailAddress, 'password123');

        $forgot = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!, $locale: String) {
              forgotPasswordUser(input: { email: $email, locale: $locale }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: [
            'email' => $emailAddress,
            'locale' => 'cs',
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $forgot);
        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Obnovení hesla', $email->getSubject());
        $html = $email->getHtmlBody();
        self::assertIsString($html);
        self::assertStringContainsString('lang="cs"', $html);
        self::assertStringContainsString('Kód pro obnovení', $html);
        $text = $email->getTextBody();
        self::assertIsString($text);
        self::assertStringContainsString('Tento kód vyprší za 1 hodinu.', $text);
        self::assertStringContainsString($this->extractTokenFromLastEmail(), $text);
    }

    public function testForgotPasswordInvalidLocaleDoesNotReplaceTheResetToken(): void
    {
        $email = 'graphql-reset-locale-bad@example.com';
        $this->registerVerifyAndLogin($email, 'password123');

        $forgot = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!) {
              forgotPasswordUser(input: { email: $email }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['email' => $email]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $forgot);
        $token = $this->extractTokenFromLastEmail();

        $rejected = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!, $locale: String) {
              forgotPasswordUser(input: { email: $email, locale: $locale }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: [
            'email' => $email,
            'locale' => 'de',
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('errors', $rejected);
        self::assertEmailCount(0);

        $reset = $this->graphql(<<<'GRAPHQL'
            mutation($token: String!, $password: String!) {
              resetPasswordUser(input: { token: $token, password: $password }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: [
            'token' => $token,
            'password' => 'newpassword456',
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $reset);

        $login = $this->loginMutation($email, 'newpassword456');
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $login);
    }

    public function testForgotAndResetPassword(): void
    {
        $email = 'graphql-reset@example.com';
        $oldPassword = 'password123';
        $newPassword = 'newpassword456';
        $this->registerVerifyAndLogin($email, $oldPassword);

        $forgot = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!) {
              forgotPasswordUser(input: { email: $email }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['email' => $email]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $forgot);
        self::assertEmailCount(1);
        $resetToken = $this->extractTokenFromLastEmail();

        $reset = $this->graphql(<<<'GRAPHQL'
            mutation($token: String!, $password: String!) {
              resetPasswordUser(input: { token: $token, password: $password }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['token' => $resetToken, 'password' => $newPassword]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $reset);

        $oldLogin = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!, $password: String!) {
              loginUser(input: { email: $email, password: $password }) {
                user {
                  token
                }
              }
            }
            GRAPHQL, variables: ['email' => $email, 'password' => $oldPassword]);
        self::assertArrayHasKey('errors', $oldLogin);

        $newLogin = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!, $password: String!) {
              loginUser(input: { email: $email, password: $password }) {
                user {
                  token
                  refreshToken
                }
              }
            }
            GRAPHQL, variables: ['email' => $email, 'password' => $newPassword]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $newLogin);
    }

    public function testShortResetPasswordDoesNotConsumeTheToken(): void
    {
        $email = 'graphql-short-reset@example.com';
        $oldPassword = 'password123';
        $newPassword = 'newpassword456';
        $this->registerVerifyAndLogin($email, $oldPassword);

        $forgot = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!) {
              forgotPasswordUser(input: { email: $email }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['email' => $email]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $forgot);
        self::assertEmailCount(1);
        $resetToken = $this->extractTokenFromLastEmail();

        $tooShort = $this->graphql(<<<'GRAPHQL'
            mutation($token: String!, $password: String!) {
              resetPasswordUser(input: { token: $token, password: $password }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['token' => $resetToken, 'password' => 'short']);
        $this->assertGraphQlError($tooShort, Response::HTTP_UNPROCESSABLE_ENTITY, 'too short');

        $stillOld = $this->loginMutation($email, $oldPassword);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $stillOld);

        $reset = $this->graphql(<<<'GRAPHQL'
            mutation($token: String!, $password: String!) {
              resetPasswordUser(input: { token: $token, password: $password }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['token' => $resetToken, 'password' => $newPassword]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $reset);

        $oldLogin = $this->loginMutation($email, $oldPassword);
        $this->assertGraphQlError($oldLogin, Response::HTTP_UNAUTHORIZED, 'Invalid credentials.');

        $newLogin = $this->loginMutation($email, $newPassword);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $newLogin);
    }

    public function testResetOnUnverifiedAccountStoresPasswordButBlocksLogin(): void
    {
        $email = 'graphql-unverified-reset@example.com';
        $resetPassword = 'resetpassword1';
        $verificationPassword = 'chosen-by-inbox';

        $register = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!) {
              registerUser(input: { email: $email }) {
                user {
                  email
                }
              }
            }
            GRAPHQL, variables: ['email' => $email]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $register);
        $verificationToken = $this->extractTokenFromLastEmail();

        $forgot = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!) {
              forgotPasswordUser(input: { email: $email }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['email' => $email]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $forgot);
        $resetToken = $this->extractTokenFromLastEmail();

        $reset = $this->graphql(<<<'GRAPHQL'
            mutation($token: String!, $password: String!) {
              resetPasswordUser(input: { token: $token, password: $password }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['token' => $resetToken, 'password' => $resetPassword]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $reset);

        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        self::assertFalse($user->isVerified());
        self::assertTrue($this->hasher()->isPasswordValid($user, $resetPassword));

        $blocked = $this->loginMutation($email, $resetPassword);
        $this->assertGraphQlError($blocked, Response::HTTP_UNAUTHORIZED, 'Please verify your email before logging in.');

        $wrong = $this->loginMutation($email, 'not-the-reset-password');
        $this->assertGraphQlError($wrong, Response::HTTP_UNAUTHORIZED, 'Invalid credentials.');

        $verify = $this->graphql(<<<'GRAPHQL'
            mutation($token: String!, $password: String!) {
              verifyEmailUser(input: { token: $token, password: $password }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: [
            'token' => $verificationToken,
            'password' => $verificationPassword,
        ]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $verify);

        $oldLogin = $this->loginMutation($email, $resetPassword);
        $this->assertGraphQlError($oldLogin, Response::HTTP_UNAUTHORIZED, 'Invalid credentials.');

        $login = $this->loginMutation($email, $verificationPassword);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $login);
    }

    public function testForgotPasswordForUnknownEmailSendsNothing(): void
    {
        $forgot = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!) {
              forgotPasswordUser(input: { email: $email }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['email' => 'nobody-graphql@example.com']);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $forgot);
        self::assertEmailCount(0);

        $data = $this->stringKeyedArray($forgot['data'] ?? null, 'forgot data');
        $payload = $this->stringKeyedArray($data['forgotPasswordUser'] ?? null, 'forgotPasswordUser');
        $user = $this->stringKeyedArray($payload['user'] ?? null, 'forgotPasswordUser.user');
        self::assertTrue($user['success'] ?? false);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

final class AuthGraphQlRegistrationTest extends WebTestCase
{
    use AuthGraphQlTestTrait;

    public function testRegisterEmailUsesCzechLocale(): void
    {
        $register = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!, $locale: String) {
              registerUser(input: { email: $email, locale: $locale }) {
                user {
                  email
                }
              }
            }
            GRAPHQL, variables: [
            'email' => 'graphql-locale-cs@example.com',
            'locale' => 'cs',
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $register);
        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Ověření e-mailu', $email->getSubject());
        $html = $email->getHtmlBody();
        self::assertIsString($html);
        self::assertStringContainsString('lang="cs"', $html);
        self::assertStringContainsString($this->extractTokenFromLastEmail(), $html);
    }

    public function testRegisterNullLocaleFallsBackToEnglish(): void
    {
        $register = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!, $locale: String) {
              registerUser(input: { email: $email, locale: $locale }) {
                user {
                  email
                }
              }
            }
            GRAPHQL, variables: [
            'email' => 'graphql-locale-null@example.com',
            'locale' => null,
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $register);
        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        $html = $email->getHtmlBody();
        self::assertIsString($html);
        self::assertStringContainsString('lang="en"', $html);
    }

    public function testRegisterInvalidLocaleDoesNotCreateTheAccount(): void
    {
        $email = 'graphql-locale-bad@example.com';
        $rejected = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!, $locale: String) {
              registerUser(input: { email: $email, locale: $locale }) {
                user {
                  email
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
        self::assertNull($this->users()->findOneByEmail($email));
    }

    public function testRegisterVerifyLoginMeAndLogout(): void
    {
        $email = 'graphql-auth@example.com';
        $password = 'Blue-Otter-Canoe-77';

        $register = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!) {
              registerUser(input: { email: $email }) {
                user {
                  email
                  id
                }
              }
            }
            GRAPHQL, variables: ['email' => $email]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $register);
        $registerData = $this->stringKeyedArray($register['data'] ?? null, 'register data');
        $registered = $this->stringKeyedArray($registerData['registerUser'] ?? null, 'registerUser');
        $user = $this->stringKeyedArray($registered['user'] ?? null, 'registerUser.user');
        self::assertSame($email, $user['email'] ?? null);
        self::assertEmailCount(1);
        $verificationToken = $this->extractTokenFromLastEmail();

        $verify = $this->graphql(<<<'GRAPHQL'
            mutation($token: String!, $password: String!) {
              verifyEmailUser(input: { token: $token, password: $password }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['token' => $verificationToken, 'password' => $password]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $verify);

        $login = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!, $password: String!) {
              loginUser(input: { email: $email, password: $password }) {
                user {
                  token
                  refreshToken
                }
              }
            }
            GRAPHQL, variables: ['email' => $email, 'password' => $password]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $login);
        $loginData = $this->stringKeyedArray($login['data'] ?? null, 'login data');
        $loginPayload = $this->stringKeyedArray($loginData['loginUser'] ?? null, 'loginUser');
        $tokens = $this->stringKeyedArray($loginPayload['user'] ?? null, 'loginUser.user');
        $token = $tokens['token'] ?? null;
        $refreshToken = $tokens['refreshToken'] ?? null;
        self::assertIsString($token);
        self::assertIsString($refreshToken);
        self::assertNotSame('', $token);
        self::assertNotSame('', $refreshToken);

        $me = $this->graphql(<<<'GRAPHQL'
            {
              meUser {
                email
                roles
              }
            }
            GRAPHQL, $token);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $me);
        $meData = $this->stringKeyedArray($me['data'] ?? null, 'me data');
        $meUser = $this->stringKeyedArray($meData['meUser'] ?? null, 'meUser');
        self::assertSame($email, $meUser['email'] ?? null);
        $roles = $meUser['roles'] ?? null;
        if (!\is_array($roles)) {
            self::fail('Expected roles array.');
        }
        self::assertContains('ROLE_USER', $roles);

        $logout = $this->graphql(<<<'GRAPHQL'
            mutation($refreshToken: String) {
              logoutUser(input: { refreshToken: $refreshToken }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, $token, ['refreshToken' => $refreshToken]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $logout);

        $meAfterLogout = $this->graphql(<<<'GRAPHQL'
            {
              meUser {
                email
              }
            }
            GRAPHQL, $token);
        $this->assertGraphQlAccessDenied($meAfterLogout);

        $refreshAfterLogout = $this->graphql(<<<'GRAPHQL'
            mutation($refreshToken: String!) {
              refreshTokenUser(input: { refreshToken: $refreshToken }) {
                user {
                  token
                }
              }
            }
            GRAPHQL, variables: ['refreshToken' => $refreshToken]);
        self::assertArrayHasKey('errors', $refreshAfterLogout);
    }

    public function testStaleBearerDoesNotBlockPublicAuthMutations(): void
    {
        $email = 'graphql-stale-bearer@example.com';
        $password = 'Blue-Otter-Canoe-77';
        $tokens = $this->registerVerifyAndLogin($email, $password);
        $staleJwt = 'not-a-valid-jwt';

        $me = $this->graphql(<<<'GRAPHQL'
            {
              meUser {
                email
              }
            }
            GRAPHQL, $staleJwt);
        $this->assertGraphQlAccessDenied($me);

        $refresh = $this->graphql(<<<'GRAPHQL'
            mutation($refreshToken: String!) {
              refreshTokenUser(input: { refreshToken: $refreshToken }) {
                user {
                  token
                  refreshToken
                }
              }
            }
            GRAPHQL, $staleJwt, ['refreshToken' => $tokens['refreshToken']]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $refresh);

        $login = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!, $password: String!) {
              loginUser(input: { email: $email, password: $password }) {
                user {
                  token
                  refreshToken
                }
              }
            }
            GRAPHQL, $staleJwt, ['email' => $email, 'password' => $password]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $login);

        $register = $this->graphql(<<<'GRAPHQL'
            mutation {
              registerUser(input: { email: "graphql-stale-register@example.com" }) {
                user {
                  email
                }
              }
            }
            GRAPHQL, $staleJwt);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $register);

        $blocklisted = $this->tokensFromLogin($login);
        $logout = $this->graphql(<<<'GRAPHQL'
            mutation($refreshToken: String) {
              logoutUser(input: { refreshToken: $refreshToken }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, $blocklisted['token'], ['refreshToken' => $blocklisted['refreshToken']]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $logout);

        $loginAgain = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!, $password: String!) {
              loginUser(input: { email: $email, password: $password }) {
                user {
                  token
                }
              }
            }
            GRAPHQL, $blocklisted['token'], ['email' => $email, 'password' => $password]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $loginAgain);
    }

    public function testRegisterWorksWithoutBearerAndStampQueryStaysProtected(): void
    {
        $register = $this->graphql(<<<'GRAPHQL'
            mutation {
              registerUser(input: { email: "public@example.com" }) {
                user {
                  email
                }
              }
            }
            GRAPHQL);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $register);

        $stamps = $this->graphql(<<<'GRAPHQL'
            {
              stamps {
                edges {
                  node {
                    name
                  }
                }
              }
            }
            GRAPHQL);

        if (Response::HTTP_UNAUTHORIZED === $this->client->getResponse()->getStatusCode()) {
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

            return;
        }

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('errors', $stamps);
    }

    public function testInvalidRegisterPayloadReturnsValidationErrors(): void
    {
        $payload = $this->graphql(<<<'GRAPHQL'
            mutation {
              registerUser(input: { email: "not-an-email" }) {
                user {
                  email
                }
              }
            }
            GRAPHQL);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('errors', $payload);
        $errors = $payload['errors'] ?? null;
        if (!\is_array($errors) || [] === $errors) {
            self::fail('Expected GraphQL validation errors.');
        }
        $first = $errors[0] ?? null;
        if (!\is_array($first)) {
            self::fail('Expected first GraphQL error to be an array.');
        }
        $extensions = $first['extensions'] ?? null;
        if (\is_array($extensions)) {
            self::assertSame(422, $extensions['status'] ?? null);
        }
    }

    public function testDuplicateRegistrationReturnsValidationError(): void
    {
        $email = 'graphql-dup@example.com';
        $password = 'Blue-Otter-Canoe-77';

        $first = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!) {
              registerUser(input: { email: $email }) {
                user {
                  email
                }
              }
            }
            GRAPHQL, variables: ['email' => $email]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $first);
        self::assertEmailCount(1);
        $verificationToken = $this->extractTokenFromLastEmail();

        $verify = $this->graphql(<<<'GRAPHQL'
            mutation($token: String!, $password: String!) {
              verifyEmailUser(input: { token: $token, password: $password }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['token' => $verificationToken, 'password' => $password]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $verify);

        $duplicate = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!) {
              registerUser(input: { email: $email }) {
                user {
                  email
                }
              }
            }
            GRAPHQL, variables: ['email' => strtoupper($email)]);
        $this->assertGraphQlError($duplicate, Response::HTTP_UNPROCESSABLE_ENTITY, 'An account with this email already exists.');

        $users = static::getContainer()->get(UserRepository::class);
        self::assertNotNull($users->findOneByEmail($email));

        $login = $this->loginMutation($email, $password);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $login);
    }

    public function testShortVerificationPasswordDoesNotConsumeTheToken(): void
    {
        $email = 'graphql-short-verify@example.com';
        $password = 'Blue-Otter-Canoe-77';

        $this->graphql(<<<'GRAPHQL'
            mutation($email: String!) {
              registerUser(input: { email: $email }) {
                user {
                  email
                }
              }
            }
            GRAPHQL, variables: ['email' => $email]);
        self::assertResponseIsSuccessful();
        $token = $this->extractTokenFromLastEmail();

        $tooShort = $this->graphql(<<<'GRAPHQL'
            mutation($token: String!, $password: String!) {
              verifyEmailUser(input: { token: $token, password: $password }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['token' => $token, 'password' => 'short']);
        $this->assertGraphQlError($tooShort, Response::HTTP_UNPROCESSABLE_ENTITY, 'too short');

        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        self::assertFalse($user->isVerified());
        self::assertNotNull($user->getEmailVerificationToken());

        $verify = $this->graphql(<<<'GRAPHQL'
            mutation($token: String!, $password: String!) {
              verifyEmailUser(input: { token: $token, password: $password }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['token' => $token, 'password' => $password]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $verify);

        $login = $this->loginMutation($email, $password);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $login);
    }

    public function testRegisterRejectsAPasswordArgument(): void
    {
        $email = 'graphql-leftover@example.com';

        $rejected = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!, $password: String!) {
              registerUser(input: { email: $email, password: $password }) {
                user {
                  email
                }
              }
            }
            GRAPHQL, variables: ['email' => $email, 'password' => 'attacker-password']);

        self::assertArrayHasKey('errors', $rejected);
        self::assertNull($this->users()->findOneByEmail($email));

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

        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('', $user->getPassword());
    }
}

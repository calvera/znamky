<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthGraphQlTest extends WebTestCase
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

    public function testRegisterVerifyLoginMeAndLogout(): void
    {
        $email = 'graphql-auth@example.com';
        $password = 'password123';

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

    public function testRefreshRotatesToken(): void
    {
        $tokens = $this->registerVerifyAndLogin('graphql-refresh@example.com', 'password123');

        $refresh = $this->graphql(<<<'GRAPHQL'
            mutation($refreshToken: String!) {
              refreshTokenUser(input: { refreshToken: $refreshToken }) {
                user {
                  token
                  refreshToken
                }
              }
            }
            GRAPHQL, variables: ['refreshToken' => $tokens['refreshToken']]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $refresh);
        $refreshData = $this->stringKeyedArray($refresh['data'] ?? null, 'refresh data');
        $refreshPayload = $this->stringKeyedArray($refreshData['refreshTokenUser'] ?? null, 'refreshTokenUser');
        $newTokens = $this->stringKeyedArray($refreshPayload['user'] ?? null, 'refreshTokenUser.user');
        self::assertNotSame($tokens['refreshToken'], $newTokens['refreshToken'] ?? null);
        self::assertNotEmpty($newTokens['token'] ?? null);

        $reuse = $this->graphql(<<<'GRAPHQL'
            mutation($refreshToken: String!) {
              refreshTokenUser(input: { refreshToken: $refreshToken }) {
                user {
                  token
                }
              }
            }
            GRAPHQL, variables: ['refreshToken' => $tokens['refreshToken']]);
        self::assertArrayHasKey('errors', $reuse);
    }

    public function testRefreshReuseRevokesTheTokenFamily(): void
    {
        $tokens = $this->registerVerifyAndLogin('graphql-refresh-reuse@example.com', 'password123');

        $refresh = $this->graphql(<<<'GRAPHQL'
            mutation($refreshToken: String!) {
              refreshTokenUser(input: { refreshToken: $refreshToken }) {
                user {
                  token
                  refreshToken
                }
              }
            }
            GRAPHQL, variables: ['refreshToken' => $tokens['refreshToken']]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $refresh);
        $refreshData = $this->stringKeyedArray($refresh['data'] ?? null, 'refresh data');
        $refreshPayload = $this->stringKeyedArray($refreshData['refreshTokenUser'] ?? null, 'refreshTokenUser');
        $rotated = $this->stringKeyedArray($refreshPayload['user'] ?? null, 'refreshTokenUser.user');
        $newRefresh = $rotated['refreshToken'] ?? null;
        self::assertIsString($newRefresh);
        self::assertNotSame($tokens['refreshToken'], $newRefresh);

        $reuse = $this->graphql(<<<'GRAPHQL'
            mutation($refreshToken: String!) {
              refreshTokenUser(input: { refreshToken: $refreshToken }) {
                user {
                  token
                }
              }
            }
            GRAPHQL, variables: ['refreshToken' => $tokens['refreshToken']]);
        self::assertArrayHasKey('errors', $reuse);

        $afterRevoke = $this->graphql(<<<'GRAPHQL'
            mutation($refreshToken: String!) {
              refreshTokenUser(input: { refreshToken: $refreshToken }) {
                user {
                  token
                }
              }
            }
            GRAPHQL, variables: ['refreshToken' => $newRefresh]);
        self::assertArrayHasKey('errors', $afterRevoke);
    }

    public function testStaleBearerDoesNotBlockPublicAuthMutations(): void
    {
        $email = 'graphql-stale-bearer@example.com';
        $password = 'password123';
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

    public function testLoginRejectsUnverifiedAccountAndAcceptsRegisteredEmailCasing(): void
    {
        $email = 'Alice.GraphQl@Example.com';
        $password = 'password123';

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
        self::assertEmailCount(1);
        $verificationToken = $this->extractTokenFromLastEmail();

        $wrongPassword = $this->loginMutation($email, 'wrong-password');
        $this->assertGraphQlError($wrongPassword, Response::HTTP_UNAUTHORIZED, 'Invalid credentials.');

        // No password is stored until verify-email, so login before verify is always invalid credentials.
        $unverified = $this->loginMutation($email, $password);
        $this->assertGraphQlError($unverified, Response::HTTP_UNAUTHORIZED, 'Invalid credentials.');

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
            'password' => $password,
        ]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $verify);

        $login = $this->loginMutation($email, $password);
        self::assertResponseIsSuccessful();
        $tokens = $this->tokensFromLogin($login);

        $me = $this->graphql(<<<'GRAPHQL'
            {
              meUser {
                email
              }
            }
            GRAPHQL, $tokens['token']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $me);
        $meData = $this->stringKeyedArray($me['data'] ?? null, 'me data');
        $meUser = $this->stringKeyedArray($meData['meUser'] ?? null, 'meUser');
        self::assertSame(strtolower($email), $meUser['email'] ?? null);

        $normalized = $this->loginMutation(strtolower($email), $password);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $normalized);
    }

    public function testFailedGraphQlLoginsAreThrottledAndSuccessResetsTheUsernameLimit(): void
    {
        $email = 'graphql-throttle@example.com';
        $password = 'password123';
        $this->registerVerifyAndLogin($email, $password);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $failed = $this->loginMutation($email, 'wrong-password');
            $this->assertGraphQlError($failed, Response::HTTP_UNAUTHORIZED, 'Invalid credentials.');
        }

        $blocked = $this->loginMutation($email, $password);
        $this->assertGraphQlError($blocked, Response::HTTP_UNAUTHORIZED, 'Too many failed login attempts');

        $this->clearRateLimiters();

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $failed = $this->loginMutation($email, 'wrong-password');
            $this->assertGraphQlError($failed, Response::HTTP_UNAUTHORIZED, 'Invalid credentials.');
        }

        $success = $this->loginMutation($email, $password);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $success);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $failed = $this->loginMutation($email, 'wrong-password');
            $this->assertGraphQlError($failed, Response::HTTP_UNAUTHORIZED, 'Invalid credentials.');
        }

        $blockedAgain = $this->loginMutation($email, 'wrong-password');
        $this->assertGraphQlError($blockedAgain, Response::HTTP_UNAUTHORIZED, 'Too many failed login attempts');
    }

    public function testEmailAndTokenRateLimitsAreIndependent(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $register = $this->graphql(<<<'GRAPHQL'
                mutation($email: String!) {
                  registerUser(input: { email: $email }) {
                    user {
                      email
                    }
                  }
                }
                GRAPHQL, variables: [
                'email' => sprintf('graphql-limit-%d@example.com', $i),
            ]);
            self::assertResponseIsSuccessful();
            self::assertArrayNotHasKey('errors', $register, sprintf('register %d should succeed', $i));
        }

        $forgot = $this->graphql(<<<'GRAPHQL'
            mutation($email: String!) {
              forgotPasswordUser(input: { email: $email }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['email' => 'graphql-limit-missing@example.com']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $forgot);

        $blockedRegister = $this->graphql(<<<'GRAPHQL'
            mutation {
              registerUser(input: { email: "graphql-limit-overflow@example.com" }) {
                user {
                  email
                }
              }
            }
            GRAPHQL);
        $this->assertGraphQlError($blockedRegister, Response::HTTP_TOO_MANY_REQUESTS, 'Too Many Requests');

        for ($i = 1; $i <= 5; ++$i) {
            $verify = $this->graphql(<<<'GRAPHQL'
                mutation($token: String!, $password: String!) {
                  verifyEmailUser(input: { token: $token, password: $password }) {
                    user {
                      success
                    }
                  }
                }
                GRAPHQL, variables: [
                'token' => sprintf('not-a-real-token-%d', $i),
                'password' => 'password123',
            ]);
            $this->assertGraphQlError($verify, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid verification token.');
        }

        $blockedVerify = $this->graphql(<<<'GRAPHQL'
            mutation {
              verifyEmailUser(input: { token: "not-a-real-token-overflow", password: "password123" }) {
                user {
                  success
                }
              }
            }
            GRAPHQL);
        $this->assertGraphQlError($blockedVerify, Response::HTTP_TOO_MANY_REQUESTS, 'Too Many Requests');
    }

    public function testDuplicateRegistrationReturnsValidationError(): void
    {
        $email = 'graphql-dup@example.com';
        $password = 'password123';

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

    private function users(): UserRepository
    {
        return static::getContainer()->get(UserRepository::class);
    }

    private function hasher(): UserPasswordHasherInterface
    {
        return static::getContainer()->get(UserPasswordHasherInterface::class);
    }

    /**
     * @return array{token: string, refreshToken: string}
     */
    private function registerVerifyAndLogin(string $email, string $password): array
    {
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
        self::assertEmailCount(1);

        $this->graphql(<<<'GRAPHQL'
            mutation($token: String!, $password: String!) {
              verifyEmailUser(input: { token: $token, password: $password }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: [
            'token' => $this->extractTokenFromLastEmail(),
            'password' => $password,
        ]);
        self::assertResponseIsSuccessful();

        $login = $this->loginMutation($email, $password);
        self::assertResponseIsSuccessful();

        return $this->tokensFromLogin($login);
    }

    /**
     * @return array<string, mixed>
     */
    private function loginMutation(string $email, string $password): array
    {
        return $this->graphql(<<<'GRAPHQL'
            mutation($email: String!, $password: String!) {
              loginUser(input: { email: $email, password: $password }) {
                user {
                  token
                  refreshToken
                }
              }
            }
            GRAPHQL, variables: ['email' => $email, 'password' => $password]);
    }

    /**
     * @param array<string, mixed> $login
     *
     * @return array{token: string, refreshToken: string}
     */
    private function tokensFromLogin(array $login): array
    {
        self::assertArrayNotHasKey('errors', $login);

        $loginData = $this->stringKeyedArray($login['data'] ?? null, 'login data');
        $loginPayload = $this->stringKeyedArray($loginData['loginUser'] ?? null, 'loginUser');
        $tokens = $this->stringKeyedArray($loginPayload['user'] ?? null, 'loginUser.user');
        $token = $tokens['token'] ?? null;
        $refreshToken = $tokens['refreshToken'] ?? null;
        if (!\is_string($token) || !\is_string($refreshToken)) {
            self::fail('Expected loginUser token and refreshToken strings.');
        }

        return ['token' => $token, 'refreshToken' => $refreshToken];
    }

    /**
     * @param array<string, mixed> $payload
     */
    /**
     * @param array<string, mixed> $payload
     */
    private function assertGraphQlAccessDenied(array $payload): void
    {
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('errors', $payload);
        $this->assertGraphQlError($payload, Response::HTTP_FORBIDDEN, 'Access Denied');
    }

    private function assertGraphQlError(array $payload, int $status, string $messagePart): void
    {
        if (
            Response::HTTP_TOO_MANY_REQUESTS === $status
            && Response::HTTP_TOO_MANY_REQUESTS === $this->client->getResponse()->getStatusCode()
        ) {
            return;
        }

        $errors = $payload['errors'] ?? null;
        if (!\is_array($errors) || [] === $errors) {
            self::fail(sprintf('Expected GraphQL error %d containing "%s".', $status, $messagePart));
        }

        foreach ($errors as $error) {
            if (!\is_array($error)) {
                continue;
            }

            $message = $error['message'] ?? null;
            if (!\is_string($message) || !str_contains($message, $messagePart)) {
                continue;
            }

            $extensions = $error['extensions'] ?? null;
            if (!\is_array($extensions)) {
                continue;
            }

            if ($status === ($extensions['status'] ?? null)) {
                return;
            }
        }

        self::fail(sprintf(
            'Expected GraphQL error %d containing "%s". Body: %s',
            $status,
            $messagePart,
            $this->client->getResponse()->getContent() ?: '',
        ));
    }

    /**
     * @param array<string, mixed>|null $variables
     *
     * @return array<string, mixed>
     */
    private function graphql(string $query, ?string $token = null, ?array $variables = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $payload = ['query' => $query];
        if (null !== $variables) {
            $payload['variables'] = $variables;
        }

        $this->client->request(
            'POST',
            '/api/graphql',
            server: $server,
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        return $this->jsonResponse();
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

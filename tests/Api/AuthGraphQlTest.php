<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

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
            mutation($email: String!, $password: String!) {
              registerUser(input: { email: $email, password: $password }) {
                user {
                  email
                  id
                }
              }
            }
            GRAPHQL, variables: ['email' => $email, 'password' => $password]);

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

        $this->graphql(<<<'GRAPHQL'
            {
              meUser {
                email
              }
            }
            GRAPHQL, $token);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

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

    public function testRegisterWorksWithoutBearerAndStampQueryStaysProtected(): void
    {
        $register = $this->graphql(<<<'GRAPHQL'
            mutation {
              registerUser(input: { email: "public@example.com", password: "password123" }) {
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
              registerUser(input: { email: "not-an-email", password: "short" }) {
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

    /**
     * @return array{token: string, refreshToken: string}
     */
    private function registerVerifyAndLogin(string $email, string $password): array
    {
        $this->graphql(<<<'GRAPHQL'
            mutation($email: String!, $password: String!) {
              registerUser(input: { email: $email, password: $password }) {
                user {
                  email
                }
              }
            }
            GRAPHQL, variables: ['email' => $email, 'password' => $password]);
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
        if (!\is_string($token) || !\is_string($refreshToken)) {
            self::fail('Expected loginUser token and refreshToken strings.');
        }

        return ['token' => $token, 'refreshToken' => $refreshToken];
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

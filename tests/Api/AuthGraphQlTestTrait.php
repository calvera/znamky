<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\RefreshToken;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

trait AuthGraphQlTestTrait
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

    /**
     * @return array<string, mixed>
     */
    private function refreshMutation(string $refreshToken): array
    {
        return $this->graphql(<<<'GRAPHQL'
            mutation($refreshToken: String!) {
              refreshTokenUser(input: { refreshToken: $refreshToken }) {
                user {
                  token
                  refreshToken
                }
              }
            }
            GRAPHQL, variables: ['refreshToken' => $refreshToken]);
    }

    /**
     * @param array<string, mixed> $refresh
     *
     * @return array{token: string, refreshToken: string}
     */
    private function tokensFromRefresh(array $refresh): array
    {
        self::assertArrayNotHasKey('errors', $refresh);

        $data = $this->stringKeyedArray($refresh['data'] ?? null, 'refresh data');
        $payload = $this->stringKeyedArray($data['refreshTokenUser'] ?? null, 'refreshTokenUser');
        $tokens = $this->stringKeyedArray($payload['user'] ?? null, 'refreshTokenUser.user');
        $token = $tokens['token'] ?? null;
        $refreshToken = $tokens['refreshToken'] ?? null;
        if (!\is_string($token) || !\is_string($refreshToken)) {
            self::fail('Expected refreshTokenUser token and refreshToken strings.');
        }

        return ['token' => $token, 'refreshToken' => $refreshToken];
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function refreshTokenEntity(string $email): RefreshToken
    {
        $this->entityManager()->clear();
        $token = $this->entityManager()->getRepository(RefreshToken::class)->findOneBy([
            'username' => strtolower($email),
        ]);
        self::assertInstanceOf(RefreshToken::class, $token);

        return $token;
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
    private function assertGraphQlAccessDenied(array $payload): void
    {
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('errors', $payload);
        $this->assertGraphQlError($payload, Response::HTTP_FORBIDDEN, 'Access Denied');
    }

    /**
     * @param array<string, mixed> $payload
     */
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

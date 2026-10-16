<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Safe\DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

trait AuthApiTestTrait
{
    use ClockSensitiveTrait;
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

    private function assertJsonError(int $status, string $detail): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $body = $this->jsonResponse();
        self::assertSame($status, $body['status'] ?? null);
        self::assertSame($detail, $body['detail'] ?? null);
        self::assertArrayHasKey('title', $body);
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

    private function waitForNextSecond(): void
    {
        $start = time();
        while (time() === $start) {
            usleep(50_000);
        }
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

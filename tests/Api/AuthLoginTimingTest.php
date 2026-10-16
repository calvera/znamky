<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherAwareInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * A failed login costs one password hash whether or not the account exists or is verified,
 * so response time does not reveal which emails have accounts.
 *
 * Counting hash operations stands in for wall-clock timing, which is too noisy to assert on.
 */
final class AuthLoginTimingTest extends WebTestCase
{
    use RateLimiterTestTrait;

    private KernelBrowser $client;
    private CountingPasswordHasherFactory $hashers;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiters();
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('TRUNCATE TABLE refresh_tokens, users RESTART IDENTITY CASCADE');

        $this->hashers = new CountingPasswordHasherFactory(new PasswordHasherFactory([
            PasswordAuthenticatedUserInterface::class => ['algorithm' => 'auto', 'cost' => 4],
        ]));
        static::getContainer()->set('security.password_hasher_factory', $this->hashers);

        $this->createUser('known@example.com', verified: true);
        $this->createUser('unverified@example.com', verified: false);
    }

    public function testRestLoginFailuresEachCostOnePasswordHash(): void
    {
        foreach (['unverified@example.com', 'nobody@example.com', 'known@example.com'] as $email) {
            $this->hashers->operations = 0;
            $this->client->request(
                'POST',
                '/api/login',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['email' => $email, 'password' => 'wrong-password'], \JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
            self::assertSame(1, $this->hashers->operations, $email);
        }
    }

    public function testGraphQlLoginFailuresEachCostOnePasswordHash(): void
    {
        foreach (['unverified@example.com', 'nobody@example.com', 'known@example.com'] as $email) {
            $this->hashers->operations = 0;
            $this->client->request(
                'POST',
                '/api/graphql',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode([
                    'query' => 'mutation($email: String!) { loginUser(input: { email: $email, password: "wrong-password" }) { user { token } } }',
                    'variables' => ['email' => $email],
                ], \JSON_THROW_ON_ERROR),
            );
            self::assertStringContainsString('"errors"', $this->client->getResponse()->getContent() ?: '');
            self::assertSame(1, $this->hashers->operations, $email);
        }
    }

    private function createUser(string $email, bool $verified): void
    {
        $user = (new User())->setEmail($email)->setIsVerified($verified);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Blue-Otter-Canoe-77'));
        static::getContainer()->get(UserRepository::class)->save($user);
    }
}

/**
 * Counts hash() and verify() calls across every hasher it hands out.
 */
final class CountingPasswordHasherFactory implements PasswordHasherFactoryInterface
{
    public int $operations = 0;

    public function __construct(private readonly PasswordHasherFactoryInterface $inner)
    {
    }

    public function getPasswordHasher(string|PasswordAuthenticatedUserInterface|PasswordHasherAwareInterface $user): PasswordHasherInterface
    {
        $inner = $this->inner->getPasswordHasher($user);
        $count = function (): void {
            ++$this->operations;
        };

        return new class($inner, $count) implements PasswordHasherInterface {
            public function __construct(private readonly PasswordHasherInterface $inner, private readonly \Closure $count)
            {
            }

            public function hash(#[\SensitiveParameter] string $plainPassword): string
            {
                ($this->count)();

                return $this->inner->hash($plainPassword);
            }

            public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
            {
                ($this->count)();

                return $this->inner->verify($hashedPassword, $plainPassword);
            }

            public function needsRehash(string $hashedPassword): bool
            {
                return $this->inner->needsRehash($hashedPassword);
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\RefreshToken;
use App\Entity\User;
use Safe\DateTime;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

final class AuthGraphQlLoginRefreshTest extends WebTestCase
{
    use AuthGraphQlTestTrait;

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

    public function testRefreshReuseUsesOnlyTheMutationToken(): void
    {
        $tokens = $this->registerVerifyAndLogin('graphql-refresh-mutation-only@example.com', 'password123');

        $this->client->request(
            'POST',
            '/api/graphql?refresh_token=decoy-query-value',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_COOKIE' => 'refresh_token=decoy-cookie-value',
            ],
            content: json_encode([
                'query' => <<<'GRAPHQL'
                    mutation($refreshToken: String!) {
                      refreshTokenUser(input: { refreshToken: $refreshToken }) {
                        user {
                          token
                          refreshToken
                        }
                      }
                    }
                    GRAPHQL,
                'variables' => ['refreshToken' => $tokens['refreshToken']],
                'refresh_token' => 'decoy-body-value',
            ], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $refresh = $this->jsonResponse();
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

    public function testRefreshRejectsUnverifiedAccountWithoutConsumingTheToken(): void
    {
        $email = 'graphql-unverified-refresh@example.com';
        $password = 'password123';
        $tokens = $this->registerVerifyAndLogin($email, $password);

        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        $user->setIsVerified(false);
        $this->entityManager()->flush();

        $blocked = $this->refreshMutation($tokens['refreshToken']);
        $this->assertGraphQlError($blocked, Response::HTTP_UNAUTHORIZED, 'Please verify your email before logging in.');

        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        $user->setIsVerified(true);
        $this->entityManager()->flush();

        $refresh = $this->refreshMutation($tokens['refreshToken']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $refresh);
        $rotated = $this->tokensFromRefresh($refresh);
        self::assertNotSame($tokens['refreshToken'], $rotated['refreshToken']);
    }

    public function testRefreshDoesNotIssueAUsableTokenPastTheFamilyDeadline(): void
    {
        $email = 'graphql-family-deadline@example.com';
        $tokens = $this->registerVerifyAndLogin($email, 'password123');

        $stored = $this->refreshTokenEntity($email);
        $family = $stored->getFamily();
        self::assertIsString($family);
        self::assertNotSame('', $family);
        $stored->setFamilyValid(new DateTime('-1 minute'));
        $this->entityManager()->flush();

        $refresh = $this->refreshMutation($tokens['refreshToken']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $refresh);
        $rotated = $this->tokensFromRefresh($refresh);
        self::assertNotSame($tokens['refreshToken'], $rotated['refreshToken']);

        $capped = $this->refreshTokenEntity($email);
        self::assertSame($family, $capped->getFamily());
        $valid = $capped->getValid();
        self::assertInstanceOf(\DateTimeInterface::class, $valid);
        self::assertLessThan(time(), $valid->getTimestamp());

        $again = $this->refreshMutation($rotated['refreshToken']);
        $this->assertGraphQlError($again, Response::HTTP_UNAUTHORIZED, 'Invalid refresh token.');
    }

    public function testRefreshKeepsTheTtlWhenTheFamilyDeadlineIsLater(): void
    {
        $email = 'graphql-family-ttl@example.com';
        $tokens = $this->registerVerifyAndLogin($email, 'password123');

        $stored = $this->refreshTokenEntity($email);
        $originalValid = $stored->getValid();
        self::assertInstanceOf(\DateTimeInterface::class, $originalValid);
        $family = $stored->getFamily();
        self::assertIsString($family);
        $deadline = new DateTime();
        $deadline->setTimestamp($originalValid->getTimestamp() + 30 * 86400);
        $stored->setFamilyValid($deadline);
        $this->entityManager()->flush();

        $refresh = $this->refreshMutation($tokens['refreshToken']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $refresh);

        $rotated = $this->refreshTokenEntity($email);
        self::assertSame($family, $rotated->getFamily());
        $valid = $rotated->getValid();
        $familyValid = $rotated->getFamilyValid();
        self::assertInstanceOf(\DateTimeInterface::class, $valid);
        self::assertInstanceOf(\DateTimeInterface::class, $familyValid);
        self::assertEqualsWithDelta($originalValid->getTimestamp(), $valid->getTimestamp(), 60);
        self::assertEqualsWithDelta($deadline->getTimestamp(), $familyValid->getTimestamp(), 2);
        self::assertLessThan($familyValid->getTimestamp() - 86400, $valid->getTimestamp());
    }

    public function testRefreshRejectsATokenWhoseUserWasDeleted(): void
    {
        $email = 'graphql-deleted-refresh@example.com';
        $tokens = $this->registerVerifyAndLogin($email, 'password123');

        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        $this->entityManager()->remove($user);
        $this->entityManager()->flush();

        $blocked = $this->refreshMutation($tokens['refreshToken']);
        $this->assertGraphQlError($blocked, Response::HTTP_UNAUTHORIZED, 'Invalid refresh token.');

        $this->entityManager()->clear();
        $remaining = $this->entityManager()->getRepository(RefreshToken::class)->findBy([
            'username' => $email,
        ]);
        self::assertCount(1, $remaining);

        $again = $this->refreshMutation($tokens['refreshToken']);
        $this->assertGraphQlError($again, Response::HTTP_UNAUTHORIZED, 'Invalid refresh token.');
    }
}

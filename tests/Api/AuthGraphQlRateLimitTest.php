<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AuthGraphQlRateLimitTest extends WebTestCase
{
    use AuthGraphQlTestTrait;

    public function testFailedGraphQlLoginsAreThrottledAndSuccessResetsTheUsernameLimit(): void
    {
        $email = 'graphql-throttle@example.com';
        $password = 'Blue-Otter-Canoe-77';
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

    public function testLoginsBlockedByAccountStatusAreThrottled(): void
    {
        $email = 'graphql-unverified-throttle@example.com';
        $password = 'Hidden-Lantern-Orbit-3';

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

        // A reset stores a password on the still-unverified account, so the UserChecker rejects the login.
        $this->graphql(<<<'GRAPHQL'
            mutation($email: String!) {
              forgotPasswordUser(input: { email: $email }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['email' => $email]);
        self::assertResponseIsSuccessful();
        $reset = $this->graphql(<<<'GRAPHQL'
            mutation($token: String!, $password: String!) {
              resetPasswordUser(input: { token: $token, password: $password }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, variables: ['token' => $this->extractTokenFromLastEmail(), 'password' => $password]);
        self::assertArrayNotHasKey('errors', $reset);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $rejected = $this->loginMutation($email, $password);
            $this->assertGraphQlError($rejected, Response::HTTP_UNAUTHORIZED, 'Please verify your email before logging in.');
        }

        $blocked = $this->loginMutation($email, $password);
        $this->assertGraphQlError($blocked, Response::HTTP_UNAUTHORIZED, 'Too many failed login attempts');
    }

    public function testSuccessfulGraphQlLoginsDoNotExhaustTheIpLimit(): void
    {
        $password = 'Blue-Otter-Canoe-77';
        $this->registerVerifyAndLogin('graphql-ip-budget@example.com', $password);

        // Test env allows 20 login attempts per IP. Successes must not spend that
        // budget, or one client logging in repeatedly locks out every account.
        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $login = $this->loginMutation('graphql-ip-budget@example.com', $password);
            self::assertResponseIsSuccessful();
            self::assertArrayNotHasKey('errors', $login, sprintf('successful login %d should not be throttled', $attempt));
        }

        $other = $this->registerVerifyAndLogin('graphql-ip-budget-other@example.com', $password);
        self::assertNotSame('', $other['token']);
    }

    public function testRefreshTokenMutationIsRateLimited(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $refused = $this->refreshMutation('unknown-'.$i);
            self::assertArrayHasKey('errors', $refused);
            $this->assertGraphQlError($refused, Response::HTTP_UNAUTHORIZED, 'Invalid refresh token.');
        }

        $blocked = $this->refreshMutation('unknown-overflow');
        self::assertArrayHasKey('errors', $blocked);
        $this->assertGraphQlError($blocked, Response::HTTP_TOO_MANY_REQUESTS, 'Too Many Requests');
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
                'password' => 'Blue-Otter-Canoe-77',
            ]);
            $this->assertGraphQlError($verify, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid verification token.');
        }

        $blockedVerify = $this->graphql(<<<'GRAPHQL'
            mutation {
              verifyEmailUser(input: { token: "not-a-real-token-overflow", password: "Blue-Otter-Canoe-77" }) {
                user {
                  success
                }
              }
            }
            GRAPHQL);
        $this->assertGraphQlError($blockedVerify, Response::HTTP_TOO_MANY_REQUESTS, 'Too Many Requests');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AuthGraphQlLogoutTest extends WebTestCase
{
    use AuthGraphQlTestTrait;

    public function testLogoutWithoutRefreshTokenRevokesEverySessionButNotOtherAccounts(): void
    {
        $owner = 'graphql-logout-all@example.com';
        $password = 'password123';
        $first = $this->registerVerifyAndLogin($owner, $password);
        $second = $this->tokensFromLogin($this->loginMutation($owner, $password));
        $other = $this->registerVerifyAndLogin('graphql-logout-other@example.com', $password);

        $logout = $this->graphql(<<<'GRAPHQL'
            mutation {
              logoutUser(input: {}) {
                user {
                  success
                }
              }
            }
            GRAPHQL, $first['token']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $logout);

        $me = $this->graphql(<<<'GRAPHQL'
            {
              meUser {
                email
              }
            }
            GRAPHQL, $first['token']);
        $this->assertGraphQlAccessDenied($me);

        $otherSession = $this->graphql(<<<'GRAPHQL'
            {
              meUser {
                email
              }
            }
            GRAPHQL, $second['token']);
        $this->assertGraphQlAccessDenied($otherSession);

        $otherAccount = $this->graphql(<<<'GRAPHQL'
            {
              meUser {
                email
              }
            }
            GRAPHQL, $other['token']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $otherAccount);

        $this->assertGraphQlError(
            $this->refreshMutation($first['refreshToken']),
            Response::HTTP_UNAUTHORIZED,
            'Invalid refresh token.',
        );
        $this->assertGraphQlError(
            $this->refreshMutation($second['refreshToken']),
            Response::HTTP_UNAUTHORIZED,
            'Invalid refresh token.',
        );

        $otherRefresh = $this->refreshMutation($other['refreshToken']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $otherRefresh);
    }

    public function testLogoutOfOneSessionLeavesTheOtherAccessTokenValid(): void
    {
        $email = 'graphql-logout-one@example.com';
        $password = 'password123';
        $first = $this->registerVerifyAndLogin($email, $password);
        $second = $this->tokensFromLogin($this->loginMutation($email, $password));

        $logout = $this->graphql(<<<'GRAPHQL'
            mutation($refreshToken: String) {
              logoutUser(input: { refreshToken: $refreshToken }) {
                user {
                  success
                }
              }
            }
            GRAPHQL, $first['token'], ['refreshToken' => $first['refreshToken']]);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $logout);

        $loggedOut = $this->graphql(<<<'GRAPHQL'
            {
              meUser {
                email
              }
            }
            GRAPHQL, $first['token']);
        $this->assertGraphQlAccessDenied($loggedOut);

        $stillIn = $this->graphql(<<<'GRAPHQL'
            {
              meUser {
                email
              }
            }
            GRAPHQL, $second['token']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $stillIn);

        $this->assertGraphQlError(
            $this->refreshMutation($first['refreshToken']),
            Response::HTTP_UNAUTHORIZED,
            'Invalid refresh token.',
        );

        $kept = $this->refreshMutation($second['refreshToken']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('errors', $kept);
    }
}

<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GraphQl\Query;
use App\GraphQl\Auth\AuthMutation;
use App\GraphQl\Resolver\Auth\ForgotPasswordMutationResolver;
use App\GraphQl\Resolver\Auth\LoginMutationResolver;
use App\GraphQl\Resolver\Auth\LogoutMutationResolver;
use App\GraphQl\Resolver\Auth\MeQueryResolver;
use App\GraphQl\Resolver\Auth\RefreshTokenMutationResolver;
use App\GraphQl\Resolver\Auth\RegisterMutationResolver;
use App\GraphQl\Resolver\Auth\ResetPasswordMutationResolver;
use App\GraphQl\Resolver\Auth\VerifyEmailMutationResolver;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'User',
    operations: [],
    graphQlOperations: [
        // Required so mutation payloads can reuse the item type (API Platform looks up item_query).
        new Query(
            security: 'false',
            securityMessage: 'Not available.',
        ),
        new AuthMutation(
            name: 'register',
            resolver: RegisterMutationResolver::class,
            args: [
                'email' => ['type' => 'String!'],
                'locale' => ['type' => 'String'],
            ],
        ),
        new AuthMutation(
            name: 'verifyEmail',
            resolver: VerifyEmailMutationResolver::class,
            args: [
                'token' => ['type' => 'String!'],
                'password' => ['type' => 'String!'],
            ],
        ),
        new AuthMutation(
            name: 'login',
            resolver: LoginMutationResolver::class,
            args: [
                'email' => ['type' => 'String!'],
                'password' => ['type' => 'String!'],
            ],
        ),
        new AuthMutation(
            name: 'refreshToken',
            resolver: RefreshTokenMutationResolver::class,
            args: [
                'refreshToken' => ['type' => 'String!'],
            ],
        ),
        new AuthMutation(
            name: 'forgotPassword',
            resolver: ForgotPasswordMutationResolver::class,
            args: [
                'email' => ['type' => 'String!'],
                'locale' => ['type' => 'String'],
            ],
        ),
        new AuthMutation(
            name: 'resetPassword',
            resolver: ResetPasswordMutationResolver::class,
            args: [
                'token' => ['type' => 'String!'],
                'password' => ['type' => 'String!'],
            ],
        ),
        new AuthMutation(
            name: 'logout',
            resolver: LogoutMutationResolver::class,
            args: [
                'refreshToken' => ['type' => 'String'],
            ],
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
        ),
        new Query(
            resolver: MeQueryResolver::class,
            args: [],
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            name: 'me',
            read: false,
            normalizationContext: ['groups' => ['auth:read']],
        ),
    ],
    normalizationContext: ['groups' => ['auth:read']],
)]
final class Auth
{
    #[ApiProperty(identifier: true)]
    #[Groups(['auth:read'])]
    public string $id = '';

    #[Groups(['auth:read'])]
    public ?string $email = null;

    /**
     * @var list<string>|null
     */
    #[Groups(['auth:read'])]
    public ?array $roles = null;

    #[Groups(['auth:read'])]
    public ?string $token = null;

    #[Groups(['auth:read'])]
    public ?string $refreshToken = null;

    #[Groups(['auth:read'])]
    public ?bool $success = null;

    public static function registered(int $id, string $email): self
    {
        $auth = new self();
        $auth->id = (string) $id;
        $auth->email = $email;

        return $auth;
    }

    public static function tokens(string $token, string $refreshToken): self
    {
        $auth = new self();
        $auth->id = 'token';
        $auth->token = $token;
        $auth->refreshToken = $refreshToken;

        return $auth;
    }

    public static function success(): self
    {
        $auth = new self();
        $auth->id = 'success';
        $auth->success = true;

        return $auth;
    }

    /**
     * @param list<string> $roles
     */
    public static function me(int $id, string $email, array $roles): self
    {
        $auth = new self();
        $auth->id = (string) $id;
        $auth->email = $email;
        $auth->roles = $roles;

        return $auth;
    }
}

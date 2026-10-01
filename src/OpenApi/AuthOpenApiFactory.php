<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\Model\Tag;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

#[AsDecorator(decorates: 'api_platform.openapi.factory', priority: 10)]
final readonly class AuthOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();

        $paths->addPath('/api/register', new PathItem(post: $this->registerOperation()));
        $paths->addPath('/api/verify-email', new PathItem(post: $this->tokenOperation(
            operationId: 'api_verify_email_post',
            summary: 'Verify email address',
            description: 'Confirms the account using the raw token from the verification email.',
            tokenDescription: 'Verification token from the email',
        )));
        $paths->addPath('/api/forgot-password', new PathItem(post: $this->forgotPasswordOperation()));
        $paths->addPath('/api/reset-password', new PathItem(post: $this->resetPasswordOperation()));
        $paths->addPath('/api/logout', new PathItem(post: $this->logoutOperation()));
        $paths->addPath('/api/me', new PathItem(get: $this->meOperation()));

        $tags = $openApi->getTags();
        $tags[] = new Tag(name: 'Authentication', description: 'Register, verify, login, refresh, logout, and password reset');

        return $openApi->withTags($tags);
    }

    private function registerOperation(): Operation
    {
        return new Operation(
            operationId: 'api_register_post',
            tags: ['Authentication'],
            responses: [
                (string) HttpResponse::HTTP_CREATED => new Response(
                    description: 'User created (email not verified yet)',
                    content: new \ArrayObject([
                        'application/json' => new MediaType(schema: new \ArrayObject([
                            'type' => 'object',
                            'required' => ['id', 'email'],
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'email' => ['type' => 'string', 'format' => 'email'],
                            ],
                        ])),
                    ]),
                ),
                (string) HttpResponse::HTTP_UNPROCESSABLE_ENTITY => new Response(
                    description: 'Validation failed (e.g. duplicate email or weak password)',
                ),
            ],
            summary: 'Register a new user',
            description: 'Creates an unverified user and sends a verification token by email.',
            requestBody: new RequestBody(
                description: 'Registration credentials',
                content: new \ArrayObject([
                    'application/json' => new MediaType(schema: new \ArrayObject([
                        'type' => 'object',
                        'required' => ['email', 'password'],
                        'properties' => [
                            'email' => ['type' => 'string', 'format' => 'email'],
                            'password' => ['type' => 'string', 'minLength' => 8],
                        ],
                    ])),
                ]),
                required: true,
            ),
            security: [],
        );
    }

    private function tokenOperation(string $operationId, string $summary, string $description, string $tokenDescription): Operation
    {
        return new Operation(
            operationId: $operationId,
            tags: ['Authentication'],
            responses: [
                (string) HttpResponse::HTTP_NO_CONTENT => new Response(description: 'Success'),
                (string) HttpResponse::HTTP_UNPROCESSABLE_ENTITY => new Response(description: 'Invalid or expired token'),
            ],
            summary: $summary,
            description: $description,
            requestBody: new RequestBody(
                description: $tokenDescription,
                content: new \ArrayObject([
                    'application/json' => new MediaType(schema: new \ArrayObject([
                        'type' => 'object',
                        'required' => ['token'],
                        'properties' => [
                            'token' => ['type' => 'string'],
                        ],
                    ])),
                ]),
                required: true,
            ),
            security: [],
        );
    }

    private function forgotPasswordOperation(): Operation
    {
        return new Operation(
            operationId: 'api_forgot_password_post',
            tags: ['Authentication'],
            responses: [
                (string) HttpResponse::HTTP_NO_CONTENT => new Response(
                    description: 'Always returned; does not reveal whether the email exists',
                ),
            ],
            summary: 'Request password reset email',
            description: 'If the email belongs to a user, sends a reset token by email.',
            requestBody: new RequestBody(
                description: 'Account email',
                content: new \ArrayObject([
                    'application/json' => new MediaType(schema: new \ArrayObject([
                        'type' => 'object',
                        'required' => ['email'],
                        'properties' => [
                            'email' => ['type' => 'string', 'format' => 'email'],
                        ],
                    ])),
                ]),
                required: true,
            ),
            security: [],
        );
    }

    private function resetPasswordOperation(): Operation
    {
        return new Operation(
            operationId: 'api_reset_password_post',
            tags: ['Authentication'],
            responses: [
                (string) HttpResponse::HTTP_NO_CONTENT => new Response(description: 'Password updated'),
                (string) HttpResponse::HTTP_UNPROCESSABLE_ENTITY => new Response(description: 'Invalid token or password'),
            ],
            summary: 'Reset password with email token',
            description: 'Sets a new password using the raw token from the reset email.',
            requestBody: new RequestBody(
                description: 'Reset token and new password',
                content: new \ArrayObject([
                    'application/json' => new MediaType(schema: new \ArrayObject([
                        'type' => 'object',
                        'required' => ['token', 'password'],
                        'properties' => [
                            'token' => ['type' => 'string'],
                            'password' => ['type' => 'string', 'minLength' => 8],
                        ],
                    ])),
                ]),
                required: true,
            ),
            security: [],
        );
    }

    private function logoutOperation(): Operation
    {
        return new Operation(
            operationId: 'api_logout_post',
            tags: ['Authentication'],
            responses: [
                (string) HttpResponse::HTTP_NO_CONTENT => new Response(description: 'Logged out'),
                (string) HttpResponse::HTTP_UNAUTHORIZED => new Response(description: 'Missing or invalid JWT'),
            ],
            summary: 'Logout',
            description: 'Blocklists the current JWT. Optionally revoke a refresh token in the body.',
            requestBody: new RequestBody(
                description: 'Optional refresh token to revoke',
                content: new \ArrayObject([
                    'application/json' => new MediaType(schema: new \ArrayObject([
                        'type' => 'object',
                        'properties' => [
                            'refresh_token' => ['type' => 'string'],
                        ],
                    ])),
                ]),
                required: false,
            ),
            security: [['JWT' => []]],
        );
    }

    private function meOperation(): Operation
    {
        return new Operation(
            operationId: 'api_me_get',
            tags: ['Authentication'],
            responses: [
                (string) HttpResponse::HTTP_OK => new Response(
                    description: 'Current user',
                    content: new \ArrayObject([
                        'application/json' => new MediaType(schema: new \ArrayObject([
                            'type' => 'object',
                            'required' => ['id', 'email', 'roles'],
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'email' => ['type' => 'string', 'format' => 'email'],
                                'roles' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                            ],
                        ])),
                    ]),
                ),
                (string) HttpResponse::HTTP_UNAUTHORIZED => new Response(description: 'Missing or invalid JWT'),
            ],
            summary: 'Current user',
            description: 'Returns the authenticated user profile.',
            security: [['JWT' => []]],
        );
    }
}

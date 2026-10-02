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
final class AuthOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        private readonly OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();

        $paths->addPath('/api/register', new PathItem(post: $this->registerOperation()));
        $paths->addPath('/api/verify-email', new PathItem(post: $this->verifyEmailOperation()));
        $paths->addPath('/api/forgot-password', new PathItem(post: $this->forgotPasswordOperation()));
        $paths->addPath('/api/reset-password', new PathItem(post: $this->resetPasswordOperation()));
        $paths->addPath('/api/logout', new PathItem(post: $this->logoutOperation()));
        $paths->addPath('/api/me', new PathItem(get: $this->meOperation()));

        $schemas = $openApi->getComponents()->getSchemas() ?? new \ArrayObject();
        $schemas['ValidationFailed'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['title', 'detail', 'violations'],
            'properties' => [
                'title' => ['type' => 'string', 'example' => 'Validation Failed'],
                'detail' => ['type' => 'string', 'example' => 'The given data failed validation.'],
                'violations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['propertyPath', 'message'],
                        'properties' => [
                            'propertyPath' => ['type' => 'string', 'example' => 'email'],
                            'message' => ['type' => 'string', 'example' => 'This value is not a valid email address.'],
                        ],
                    ],
                ],
            ],
        ]);

        $tags = $openApi->getTags();
        $tags[] = new Tag(name: 'Authentication', description: 'Register, verify, login, refresh, logout, and password reset');

        return $openApi
            ->withComponents($openApi->getComponents()->withSchemas($schemas))
            ->withTags($tags);
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
                (string) HttpResponse::HTTP_UNPROCESSABLE_ENTITY => $this->validationFailedResponse(
                    'Validation failed (invalid email/password or duplicate email)',
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

    private function verifyEmailOperation(): Operation
    {
        return new Operation(
            operationId: 'api_verify_email_post',
            tags: ['Authentication'],
            responses: [
                (string) HttpResponse::HTTP_NO_CONTENT => new Response(description: 'Email verified and password set'),
                (string) HttpResponse::HTTP_UNPROCESSABLE_ENTITY => $this->validationFailedResponse(
                    'Invalid payload, or invalid/expired token',
                ),
            ],
            summary: 'Verify email address',
            description: 'Confirms the account using the raw token from the verification email. The password in this request becomes the account password and replaces the one from registration.',
            requestBody: new RequestBody(
                description: 'Verification token and the password chosen by the mailbox owner',
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

    private function forgotPasswordOperation(): Operation
    {
        return new Operation(
            operationId: 'api_forgot_password_post',
            tags: ['Authentication'],
            responses: [
                (string) HttpResponse::HTTP_NO_CONTENT => new Response(
                    description: 'Always returned; does not reveal whether the email exists',
                ),
                (string) HttpResponse::HTTP_UNPROCESSABLE_ENTITY => $this->validationFailedResponse(
                    'Validation failed (invalid email)',
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
                (string) HttpResponse::HTTP_UNPROCESSABLE_ENTITY => $this->validationFailedResponse(
                    'Invalid payload, or invalid/expired token',
                ),
            ],
            summary: 'Reset password with email token',
            description: 'Sets a new password using the raw token from the reset email. Revokes all refresh tokens for the user. Does not verify the email; an unverified account must still confirm the original verification token.',
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
            description: 'Blocklists the current JWT. If `refresh_token` is sent, that refresh token is revoked; otherwise all refresh tokens for the user are revoked.',
            requestBody: new RequestBody(
                description: 'Optional refresh token to revoke (omit to revoke all for the user)',
                content: new \ArrayObject([
                    'application/json' => new MediaType(schema: new \ArrayObject([
                        'type' => 'object',
                        'properties' => [
                            'refresh_token' => ['type' => 'string', 'nullable' => true],
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

    private function validationFailedResponse(string $description): Response
    {
        return new Response(
            description: $description,
            content: new \ArrayObject([
                'application/json' => new MediaType(schema: new \ArrayObject([
                    '$ref' => '#/components/schemas/ValidationFailed',
                ])),
            ]),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\GraphQl\Auth;

use ApiPlatform\Metadata\GraphQl\Mutation;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * GraphQL auth mutation handled entirely by its resolver: no read, deserialize, validate or write
 * stages, output normalized with the `auth:read` group.
 */
#[Exclude]
final class AuthMutation extends Mutation
{
    /**
     * @param class-string                $resolver
     * @param array<string, array<mixed>> $args
     */
    public function __construct(string $name, string $resolver, array $args, string $security = 'true')
    {
        parent::__construct(
            resolver: $resolver,
            args: $args,
            security: $security,
            name: $name,
            read: false,
            deserialize: false,
            validate: false,
            write: false,
            normalizationContext: ['groups' => ['auth:read']],
        );
    }
}

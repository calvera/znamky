<?php

declare(strict_types=1);

namespace App\GraphQl;

use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AuthInputValidator
{
    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function validate(object $dto): void
    {
        $violations = $this->validator->validate($dto);
        if (0 === \count($violations)) {
            return;
        }

        throw new ValidationException($violations);
    }

    /**
     * Re-throw Symfony validator failures as API Platform GraphQL validation errors.
     */
    public function convert(ValidationFailedException $exception): never
    {
        throw new ValidationException($exception->getViolations(), previous: $exception);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function input(array $context): array
    {
        $args = $context['args'] ?? null;
        if (!\is_array($args)) {
            return [];
        }

        $input = $args['input'] ?? [];
        if (!\is_array($input)) {
            return [];
        }

        $result = [];
        foreach ($input as $key => $value) {
            if (\is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function string(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}

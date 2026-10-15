<?php

declare(strict_types=1);

namespace App\GraphQl\Auth;

use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Webmozart\Assert\Assert;

final class AuthInputValidator
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly DenormalizerInterface $denormalizer,
    ) {
    }

    /**
     * Denormalizes the mutation `input` into a request DTO and validates it.
     * Null arguments are dropped so the DTO defaults apply.
     *
     * @template T of object
     *
     * @param array<string, mixed> $context
     * @param class-string<T>      $class
     *
     * @return T
     */
    public function dto(array $context, string $class): object
    {
        $input = array_filter($this->input($context), static fn (mixed $value): bool => null !== $value);
        $dto = Assert::isInstanceOf($this->denormalizer->denormalize($input, $class), $class);
        $this->validate($dto);

        return $dto;
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

    private function validate(object $dto): void
    {
        $violations = $this->validator->validate($dto);
        if (0 === \count($violations)) {
            return;
        }

        throw new ValidationException($violations);
    }
}

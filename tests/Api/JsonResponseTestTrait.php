<?php

declare(strict_types=1);

namespace App\Tests\Api;

trait JsonResponseTestTrait
{
    /**
     * @return array<string, mixed>
     */
    private function jsonResponse(): array
    {
        $payload = json_decode($this->client->getResponse()->getContent() ?: '[]', true, 512, \JSON_THROW_ON_ERROR);

        return $this->stringKeyedArray($payload, 'JSON response');
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value, string $label): array
    {
        if (!\is_array($value)) {
            self::fail(sprintf('Expected %s to be an array.', $label));
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                self::fail(sprintf('Expected %s keys to be strings.', $label));
            }
            $result[$key] = $item;
        }

        return $result;
    }
}

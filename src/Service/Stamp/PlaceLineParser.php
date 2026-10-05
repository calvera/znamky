<?php

declare(strict_types=1);

namespace App\Service\Stamp;

use function Safe\preg_match;
use function Safe\preg_split;

/**
 * Parses "Prodejní místa (název a web)" CSV cells into name + optional URL pairs.
 */
final class PlaceLineParser
{
    /**
     * @return list<array{name: string, url: ?string}>
     */
    public function parseCell(?string $cell): array
    {
        if (null === $cell || '' === trim($cell)) {
            return [];
        }

        $lines = preg_split('/\R/u', $cell);
        $places = [];

        foreach ($lines as $line) {
            if (!\is_string($line)) {
                continue;
            }

            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $parsed = $this->parseLine($line);
            if (null !== $parsed) {
                $places[] = $parsed;
            }
        }

        return $places;
    }

    /**
     * @return array{name: string, url: ?string}|null
     */
    public function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ('' === $line) {
            return null;
        }

        if (preg_match('/^(.*)\(([^()]*)\)\s*$/u', $line, $matches)) {
            $candidate = trim($matches[2]);
            if ($this->looksLikeUrl($candidate)) {
                $name = trim($matches[1]);
                if ('' === $name) {
                    return null;
                }

                return [
                    'name' => $name,
                    'url' => $this->normalizeUrl($candidate),
                ];
            }
        }

        return [
            'name' => $line,
            'url' => null,
        ];
    }

    private function looksLikeUrl(string $value): bool
    {
        $value = trim($value);
        if ('' === $value) {
            return false;
        }

        if (preg_match('#^(https?://|www\.)#i', $value)) {
            return true;
        }

        // Bare domains like hotel-neptun.cz or facebook.com/path
        return (bool) preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}(\/.*)?$/i', $value);
    }

    private function normalizeUrl(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        if (str_starts_with(strtolower($value), 'www.')) {
            return 'https://'.$value;
        }

        return 'https://'.$value;
    }

    /**
     * @return list<string>
     */
    public function parseTags(?string $kategorie): array
    {
        if (null === $kategorie || '' === trim($kategorie)) {
            return [];
        }

        $parts = explode(',', $kategorie);
        $tags = [];
        foreach ($parts as $part) {
            $tag = trim($part);
            if ('' !== $tag) {
                $tags[] = $tag;
            }
        }

        return array_values(array_unique($tags));
    }
}

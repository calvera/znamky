<?php

declare(strict_types=1);

namespace App\Service\Stamp;

use function Safe\file_get_contents;
use function Safe\file_put_contents;
use function Safe\json_decode;
use function Safe\json_encode;
use function Safe\mkdir;

/**
 * Scrape output layout: scrape-meta.json, index.jsonl and stamps/{id}/stamp.json + images/.
 */
final class StampWebScrapeOutput
{
    private readonly string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
        $this->ensureDirectory($this->dir.'/stamps');
    }

    public function hasStamp(int $id): bool
    {
        return is_file($this->stampPath($id, 'stamp.json'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function writeStamp(int $id, array $payload): void
    {
        $path = $this->stampPath($id, 'stamp.json');
        $this->ensureDirectory(\dirname($path));
        file_put_contents($path, $this->prettyJson($payload));
    }

    /**
     * @param string $relativePath path under the stamp directory, e.g. images/current/a.jpg
     */
    public function hasStampFile(int $id, string $relativePath): bool
    {
        return is_file($this->stampPath($id, $relativePath));
    }

    public function writeStampFile(int $id, string $relativePath, string $contents): void
    {
        $path = $this->stampPath($id, $relativePath);
        $this->ensureDirectory(\dirname($path));
        file_put_contents($path, $contents);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function appendIndex(array $row): void
    {
        file_put_contents(
            $this->dir.'/index.jsonl',
            json_encode($row, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n",
            \FILE_APPEND,
        );
    }

    /**
     * Merges $meta into scrape-meta.json, keeping keys from earlier writes.
     *
     * @param array<string, mixed> $meta
     */
    public function writeMeta(array $meta): void
    {
        $path = $this->dir.'/scrape-meta.json';
        $existing = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        file_put_contents($path, $this->prettyJson(array_merge(\is_array($existing) ? $existing : [], $meta)));
    }

    private function stampPath(int $id, string $relativePath): string
    {
        return $this->dir.'/stamps/'.$id.'/'.$relativePath;
    }

    /**
     * @param array<mixed> $data
     */
    private function prettyJson(array $data): string
    {
        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n";
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }
}

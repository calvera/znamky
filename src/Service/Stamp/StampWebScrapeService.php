<?php

declare(strict_types=1);

namespace App\Service\Stamp;

use Safe\DateTimeImmutable;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Webmozart\Assert\Assert;

use function Safe\file_get_contents;
use function Safe\file_put_contents;
use function Safe\json_decode;
use function Safe\json_encode;
use function Safe\mkdir;
use function Safe\preg_match;
use function Safe\sleep;

/**
 * Downloads stamp catalog pages from turisticke-znamky.cz into per-stamp JSON + images.
 */
final class StampWebScrapeService
{
    private const BASE_URL = 'https://turisticke-znamky.cz';
    private const USER_AGENT = 'Mozilla/5.0 (compatible; ZnamkyScraper/1.0; +https://github.com/calvera/znamky)';
    private const LAZY_ROWS = 100;
    private const RATE_LIMIT_BACKOFF_START_SEC = 30;
    private const RATE_LIMIT_BACKOFF_CAP_SEC = 300;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param (callable(string, array<string, mixed>): void)|null $onEvent
     *
     * @return array{discovered: int, scraped: int, skipped: int, failed: int, images: int}
     */
    public function scrape(StampWebScrapeOptions $options, ?callable $onEvent = null): array
    {
        $outputDir = rtrim($options->outputDir, '/\\');
        $this->ensureDirectory($outputDir);
        $this->ensureDirectory($outputDir.\DIRECTORY_SEPARATOR.'stamps');

        $filters = $this->resolveFilters($options);
        $this->emit($onEvent, 'filters', $filters);

        $ids = $this->discoverIds($filters['countries'], $filters['types'], $options, $onEvent);

        if (null !== $options->limit && $options->limit >= 0) {
            $ids = \array_slice($ids, 0, $options->limit);
        }

        $this->emit($onEvent, 'discovered', ['count' => \count($ids)]);

        $stats = [
            'discovered' => \count($ids),
            'scraped' => 0,
            'skipped' => 0,
            'failed' => 0,
            'images' => 0,
        ];

        $this->writeMeta($outputDir, [
            'startedAt' => (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'baseUrl' => self::BASE_URL,
            'filters' => $filters,
            'discovered' => \count($ids),
            'options' => [
                'delayDetailMs' => $options->delayDetailMs,
                'delayListMs' => $options->delayListMs,
                'delayImageMs' => $options->delayImageMs,
                'countryId' => $options->countryId,
                'typeId' => $options->typeId,
                'limit' => $options->limit,
                'force' => $options->force,
                'skipImages' => $options->skipImages,
                'timeoutSec' => $options->timeoutSec,
                'retries' => $options->retries,
                'rateLimitRetries' => $options->rateLimitRetries,
            ],
        ]);

        $detailCount = 0;
        foreach ($ids as $id) {
            $stampDir = $outputDir.\DIRECTORY_SEPARATOR.'stamps'.\DIRECTORY_SEPARATOR.(string) $id;
            $jsonPath = $stampDir.\DIRECTORY_SEPARATOR.'stamp.json';

            if (!$options->force && is_file($jsonPath)) {
                ++$stats['skipped'];
                $this->emit($onEvent, 'skip', ['id' => $id]);
                continue;
            }

            try {
                $result = $this->scrapeOne($id, $stampDir, $options);
                $this->appendIndex($outputDir, $result['index']);
                ++$stats['scraped'];
                $stats['images'] += $result['images'];
                $this->emit($onEvent, 'scraped', ['id' => $id, 'images' => $result['images']]);
            } catch (\Throwable $e) {
                ++$stats['failed'];
                $this->emit($onEvent, 'failed', ['id' => $id, 'error' => $e->getMessage()]);
            }

            ++$detailCount;
            $this->pause($options->delayDetailMs);

            if ($options->batchPauseEvery > 0
                && $options->batchPauseMs > 0
                && 0 === $detailCount % $options->batchPauseEvery
            ) {
                $this->pause($options->batchPauseMs);
            }
        }

        $this->writeMeta($outputDir, [
            'finishedAt' => (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'stats' => $stats,
            'filters' => $filters,
            'baseUrl' => self::BASE_URL,
        ]);

        return $stats;
    }

    /**
     * @return array{item: array<string, mixed>, index: array<string, mixed>, images: int}
     */
    public function scrapeOne(int $id, string $stampDir, StampWebScrapeOptions $options): array
    {
        $url = self::BASE_URL.'/items/'.$id;
        $html = $this->requestContent($url, $options);
        $item = $this->parseItemFromInertiaHtml($html);

        $this->ensureDirectory($stampDir);
        $imageFiles = [];
        $imageCount = 0;

        if (!$options->skipImages) {
            $images = $item['images'] ?? [];
            Assert::isArray($images, 'Item images must be an array.');
            foreach ($images as $image) {
                Assert::isArray($image, 'Each image entry must be an array.');
                $src = $image['src'] ?? null;
                if (!\is_string($src) || '' === $src) {
                    continue;
                }
                $archiveRaw = $image['archive'] ?? 0;
                Assert::integerish($archiveRaw, 'Image archive flag must be integerish.');
                $archive = (int) $archiveRaw;
                $subdir = 1 === $archive ? 'archive' : 'current';
                $targetDir = $stampDir.\DIRECTORY_SEPARATOR.'images'.\DIRECTORY_SEPARATOR.$subdir;
                $this->ensureDirectory($targetDir);
                $targetPath = $targetDir.\DIRECTORY_SEPARATOR.$src;
                $relative = 'images/'.$subdir.'/'.$src;

                if ($options->force || !is_file($targetPath)) {
                    $imageUrl = self::BASE_URL.'/storage/item_images/medium/'.rawurlencode($src);
                    $binary = $this->requestContent($imageUrl, $options);
                    file_put_contents($targetPath, $binary);
                    $this->pause($options->delayImageMs);
                }

                $imageFiles[] = [
                    'src' => $src,
                    'archive' => $archive,
                    'path' => $relative,
                    'url' => self::BASE_URL.'/storage/item_images/medium/'.$src,
                ];
                ++$imageCount;
            }
        }

        $scrapedAt = (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $payload = $item;
        $payload['_scrape'] = [
            'url' => $url,
            'scrapedAt' => $scrapedAt,
            'imageFiles' => $imageFiles,
        ];

        file_put_contents(
            $stampDir.\DIRECTORY_SEPARATOR.'stamp.json',
            json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n",
        );

        $country = $item['country'] ?? null;
        $type = $item['type'] ?? null;

        return [
            'item' => $item,
            'images' => $imageCount,
            'index' => [
                'id' => $id,
                'code' => $item['code'] ?? null,
                'no' => $item['no'] ?? null,
                'name' => $item['name'] ?? null,
                'countryId' => $item['country_id'] ?? (\is_array($country) ? ($country['id'] ?? null) : null),
                'country' => \is_array($country) ? ($country['short'] ?? null) : null,
                'typeId' => \is_array($type) ? ($type['id'] ?? null) : null,
                'type' => \is_array($type) ? ($type['short'] ?? null) : null,
                'path' => 'stamps/'.$id.'/stamp.json',
                'scrapedAt' => $scrapedAt,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function parseItemFromInertiaHtml(string $html): array
    {
        $page = $this->parseInertiaPage($html);
        $item = $page['props']['item'] ?? null;
        Assert::isArray($item, 'Inertia page is missing props.item.');
        Assert::keyExists($item, 'id', 'Item is missing id.');

        return $this->stringKeyedArray($item);
    }

    /**
     * @return array{component?: string, props: array<string, mixed>, url?: string}
     */
    public function parseInertiaPage(string $html): array
    {
        $matched = preg_match('/data-page="([^"]+)"/', $html, $matches);
        if (1 !== $matched) {
            throw new \RuntimeException('Inertia data-page attribute not found in HTML response.');
        }

        $decoded = html_entity_decode($matches[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $page = json_decode($decoded, true);
        Assert::isArray($page, 'Inertia page JSON must be an object.');
        Assert::keyExists($page, 'props', 'Inertia page JSON is missing props.');
        $props = $page['props'];
        Assert::isArray($props, 'Inertia props must be an array.');

        $result = [
            'props' => $this->stringKeyedArray($props),
        ];
        if (isset($page['component']) && \is_string($page['component'])) {
            $result['component'] = $page['component'];
        }
        if (isset($page['url']) && \is_string($page['url'])) {
            $result['url'] = $page['url'];
        }

        return $result;
    }

    /**
     * @return array{
     *     countries: non-empty-list<array{id: int, short?: string, name?: string}>,
     *     types: non-empty-list<array{id: int, short?: string, name?: string}>
     * }
     */
    private function resolveFilters(StampWebScrapeOptions $options): array
    {
        $page = $this->parseInertiaPage($this->requestContent(self::BASE_URL.'/items?country=1&type=0&page=1&rows=5', $options));
        $this->pause($options->delayListMs);

        $referenceData = $page['props']['referenceData'] ?? null;
        $filtersData = $page['props']['filtersData'] ?? null;
        Assert::isArray($referenceData, 'Missing referenceData.');
        Assert::isArray($filtersData, 'Missing filtersData.');

        $countriesRaw = $referenceData['countries'] ?? null;
        $typesRaw = $filtersData['types'] ?? null;
        Assert::isArray($countriesRaw, 'Missing referenceData.countries.');
        Assert::isArray($typesRaw, 'Missing filtersData.types.');

        $countries = [];
        foreach ($countriesRaw as $country) {
            Assert::isArray($country);
            $id = $this->requireInt($country['id'] ?? null, 'Country id must be integerish.');
            if (null !== $options->countryId && $id !== $options->countryId) {
                continue;
            }
            $entry = ['id' => $id];
            if (isset($country['short']) && \is_string($country['short'])) {
                $entry['short'] = $country['short'];
            }
            if (isset($country['name']) && \is_string($country['name'])) {
                $entry['name'] = $country['name'];
            }
            $countries[] = $entry;
        }

        $types = [];
        foreach ($typesRaw as $type) {
            Assert::isArray($type);
            $id = $this->requireInt($type['id'] ?? null, 'Type id must be integerish.');
            if (null !== $options->typeId && $id !== $options->typeId) {
                continue;
            }
            $entry = ['id' => $id];
            if (isset($type['short']) && \is_string($type['short'])) {
                $entry['short'] = $type['short'];
            }
            if (isset($type['name']) && \is_string($type['name'])) {
                $entry['name'] = $type['name'];
            }
            $types[] = $entry;
        }

        Assert::notEmpty($countries, 'No countries matched the scrape filters.');
        Assert::notEmpty($types, 'No types matched the scrape filters.');

        return ['countries' => $countries, 'types' => $types];
    }

    /**
     * @param list<array{id: int, short?: string, name?: string}> $countries
     * @param list<array{id: int, short?: string, name?: string}> $types
     * @param (callable(string, array<string, mixed>): void)|null $onEvent
     *
     * @return list<int>
     */
    private function discoverIds(array $countries, array $types, StampWebScrapeOptions $options, ?callable $onEvent): array
    {
        $ids = [];
        $seen = [];
        $firstBucket = true;

        foreach ($countries as $country) {
            foreach ($types as $type) {
                if (!$firstBucket) {
                    $this->pause($options->bucketPauseMs);
                }
                $firstBucket = false;

                $this->emit($onEvent, 'bucket', ['countryId' => $country['id'], 'typeId' => $type['id']]);
                $page = 1;
                while (true) {
                    $query = http_build_query([
                        'country' => $country['id'],
                        'type' => $type['id'],
                        'sorting' => 'number',
                        'page' => $page,
                        'rows' => self::LAZY_ROWS,
                    ]);
                    $payload = json_decode($this->requestContent(self::BASE_URL.'/items/lazy?'.$query, $options), true);
                    $this->pause($options->delayListMs);

                    Assert::isArray($payload, 'Lazy listing response must be JSON object.');
                    $items = $payload['items'] ?? [];
                    Assert::isArray($items);

                    if ([] === $items) {
                        break;
                    }

                    foreach ($items as $item) {
                        Assert::isArray($item);
                        $id = $this->requireInt($item['id'] ?? null, 'Lazy item id must be integerish.');
                        if (isset($seen[$id])) {
                            continue;
                        }
                        $seen[$id] = true;
                        $ids[] = $id;
                    }

                    $total = $this->requireInt($payload['totalRecords'] ?? 0, 'totalRecords must be integerish.');
                    $perPage = $this->requireInt($payload['itemsPerPage'] ?? self::LAZY_ROWS, 'itemsPerPage must be integerish.');
                    if ($perPage <= 0 || $page * $perPage >= $total) {
                        break;
                    }
                    ++$page;
                }
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeMeta(string $outputDir, array $meta): void
    {
        $path = $outputDir.\DIRECTORY_SEPARATOR.'scrape-meta.json';
        $existing = [];
        if (is_file($path)) {
            $decoded = json_decode(file_get_contents($path), true);
            if (\is_array($decoded)) {
                $existing = $this->stringKeyedArray($decoded);
            }
        }

        file_put_contents(
            $path,
            json_encode(array_merge($existing, $meta), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function appendIndex(string $outputDir, array $row): void
    {
        file_put_contents(
            $outputDir.\DIRECTORY_SEPARATOR.'index.jsonl',
            json_encode($row, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n",
            \FILE_APPEND,
        );
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        mkdir($path, 0777, true);
    }

    private function requestContent(string $url, StampWebScrapeOptions $options): string
    {
        $rateAttempt = 0;
        $transientAttempt = 0;
        $rateLimitRetries = max(0, $options->rateLimitRetries);
        $retries = max(0, $options->retries);
        $timeout = max(1.0, $options->timeoutSec);

        while (true) {
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => $timeout,
                    'max_duration' => $timeout,
                    'headers' => [
                        'User-Agent' => self::USER_AGENT,
                        'Accept' => 'text/html,application/json,*/*',
                        'X-Requested-With' => 'XMLHttpRequest',
                    ],
                ]);

                $status = $response->getStatusCode();
                if (\in_array($status, [429, 503], true)) {
                    if ($rateAttempt >= $rateLimitRetries) {
                        throw new \RuntimeException(sprintf('HTTP %d for %s after %d rate-limit retries.', $status, $url, $rateLimitRetries));
                    }
                    $this->backoffRateLimit($response, $rateAttempt, $options);
                    ++$rateAttempt;
                    continue;
                }

                if ($status >= 500) {
                    if ($transientAttempt >= $retries) {
                        throw new \RuntimeException(sprintf('HTTP %d for %s after %d retries.', $status, $url, $retries));
                    }
                    $this->retryPause($options);
                    ++$transientAttempt;
                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException(sprintf('HTTP %d for %s.', $status, $url));
                }

                return $response->getContent(false);
            } catch (TransportExceptionInterface $e) {
                if ($transientAttempt >= $retries) {
                    throw new \RuntimeException(sprintf('Transport error for %s after %d retries: %s', $url, $retries, $e->getMessage()), 0, $e);
                }
                $this->retryPause($options);
                ++$transientAttempt;
            }
        }
    }

    private function backoffRateLimit(ResponseInterface $response, int $attempt, StampWebScrapeOptions $options): void
    {
        $headers = $response->getHeaders(false);
        $retryAfter = $headers['retry-after'][0] ?? null;
        if (\is_string($retryAfter) && ctype_digit($retryAfter)) {
            $this->retrySleep(max(1, (int) $retryAfter), $options);

            return;
        }

        $seconds = min(
            self::RATE_LIMIT_BACKOFF_CAP_SEC,
            self::RATE_LIMIT_BACKOFF_START_SEC * (2 ** $attempt),
        );
        $this->retrySleep($seconds, $options);
    }

    private function retryPause(StampWebScrapeOptions $options): void
    {
        if ($this->delaysDisabled($options)) {
            return;
        }
        $this->pause(random_int(2000, 5000));
    }

    private function retrySleep(int $seconds, StampWebScrapeOptions $options): void
    {
        if ($this->delaysDisabled($options) || $seconds <= 0) {
            return;
        }
        sleep($seconds);
    }

    private function delaysDisabled(StampWebScrapeOptions $options): bool
    {
        return $options->delayDetailMs <= 0
            && $options->delayListMs <= 0
            && $options->delayImageMs <= 0
            && $options->batchPauseMs <= 0
            && $options->bucketPauseMs <= 0;
    }

    private function pause(int $baseMs): void
    {
        if ($baseMs <= 0) {
            return;
        }

        $jitter = (int) round($baseMs * 0.2);
        $ms = $baseMs + ($jitter > 0 ? random_int(-$jitter, $jitter) : 0);
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    /**
     * @param (callable(string, array<string, mixed>): void)|null $onEvent
     * @param array<string, mixed>                                $payload
     */
    private function emit(?callable $onEvent, string $event, array $payload): void
    {
        if (null !== $onEvent) {
            $onEvent($event, $payload);
        }
    }

    private function requireInt(mixed $value, string $message): int
    {
        Assert::integerish($value, $message);

        return (int) $value;
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            Assert::string($key, 'Expected string array keys.');
            $result[$key] = $value;
        }

        return $result;
    }
}

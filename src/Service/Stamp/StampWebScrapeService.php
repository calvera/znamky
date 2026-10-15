<?php

declare(strict_types=1);

namespace App\Service\Stamp;

use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Downloads stamp catalog pages from turisticke-znamky.cz into per-stamp JSON + images.
 */
final class StampWebScrapeService
{
    private const string IMAGE_URL = StampWebClient::BASE_URL.'/storage/item_images/medium/';

    public function __construct(
        private readonly StampWebClient $client,
        private readonly InertiaPageParser $parser,
        private readonly StampCatalogDiscovery $discovery,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{discovered: int, scraped: int, skipped: int, failed: int, images: int}
     */
    public function scrape(
        StampWebScrapeOptions $options,
        StampWebScrapeListener $listener = new NullStampWebScrapeListener(),
    ): array {
        $output = new StampWebScrapeOutput($options->outputDir);

        $filters = $this->discovery->resolveFilters($options);
        $listener->filtersResolved(\count($filters['countries']), \count($filters['types']));

        $ids = $this->discovery->discoverIds($filters['countries'], $filters['types'], $options, $listener);

        if (null !== $options->limit && $options->limit >= 0) {
            $ids = \array_slice($ids, 0, $options->limit);
        }

        $listener->stampsDiscovered(\count($ids));

        $stats = [
            'discovered' => \count($ids),
            'scraped' => 0,
            'skipped' => 0,
            'failed' => 0,
            'images' => 0,
        ];

        $output->writeMeta([
            'startedAt' => $this->now(),
            'baseUrl' => StampWebClient::BASE_URL,
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
            if (!$options->force && $output->hasStamp($id)) {
                ++$stats['skipped'];
                $listener->stampSkipped($id);
                continue;
            }

            try {
                $images = $this->scrapeOne($id, $output, $options);
                ++$stats['scraped'];
                $stats['images'] += $images;
                $listener->stampScraped($id, $images);
            } catch (\Throwable $e) {
                ++$stats['failed'];
                $listener->stampFailed($id, $e->getMessage());
            }

            ++$detailCount;
            $this->client->pause($options->delayDetailMs);

            if ($options->batchPauseEvery > 0 && 0 === $detailCount % $options->batchPauseEvery) {
                $this->client->pause($options->batchPauseMs);
            }
        }

        $output->writeMeta([
            'finishedAt' => $this->now(),
            'stats' => $stats,
            'filters' => $filters,
            'baseUrl' => StampWebClient::BASE_URL,
        ]);

        return $stats;
    }

    /**
     * Writes stamp.json and images for one stamp, appends its index row.
     *
     * @return int number of images recorded
     */
    private function scrapeOne(int $id, StampWebScrapeOutput $output, StampWebScrapeOptions $options): int
    {
        $url = StampWebClient::BASE_URL.'/items/'.$id;
        $item = $this->parser->parseItem($this->client->get($url, $options));
        $imageFiles = $options->skipImages ? [] : $this->downloadImages($id, $item, $output, $options);

        $scrapedAt = $this->now();
        $output->writeStamp($id, [
            ...$item,
            '_scrape' => [
                'url' => $url,
                'scrapedAt' => $scrapedAt,
                'imageFiles' => $imageFiles,
            ],
        ]);

        $country = $item['country'] ?? null;
        $type = $item['type'] ?? null;
        $output->appendIndex([
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
        ]);

        return \count($imageFiles);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return list<array{src: string, archive: int, path: string, url: string}>
     */
    private function downloadImages(int $id, array $item, StampWebScrapeOutput $output, StampWebScrapeOptions $options): array
    {
        $images = $item['images'] ?? [];
        Assert::isArray($images, 'Item images must be an array.');

        $imageFiles = [];
        foreach ($images as $image) {
            Assert::isArray($image, 'Each image entry must be an array.');
            $src = $image['src'] ?? null;
            $safeName = \is_string($src) ? $this->safeImageFileName($src) : null;
            if (null === $safeName) {
                continue;
            }
            $archive = $this->requireInt($image['archive'] ?? 0, 'Image archive flag must be integerish.');
            $relative = 'images/'.(1 === $archive ? 'archive' : 'current').'/'.$safeName;

            if ($options->force || !$output->hasStampFile($id, $relative)) {
                $output->writeStampFile($id, $relative, $this->client->get(self::IMAGE_URL.rawurlencode($safeName), $options));
                $this->client->pause($options->delayImageMs);
            }

            $imageFiles[] = [
                'src' => $safeName,
                'archive' => $archive,
                'path' => $relative,
                'url' => self::IMAGE_URL.$safeName,
            ];
        }

        return $imageFiles;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    private function requireInt(mixed $value, string $message): int
    {
        Assert::integerish($value, $message);

        return (int) $value;
    }

    private function safeImageFileName(string $src): ?string
    {
        $safeName = basename(str_replace(["\0", '\\'], '', $src));
        if ('' === $safeName || '.' === $safeName || '..' === $safeName) {
            return null;
        }

        return $safeName;
    }
}

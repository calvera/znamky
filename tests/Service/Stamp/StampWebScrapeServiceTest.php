<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Service\Stamp\StampWebScrapeOptions;
use App\Service\Stamp\StampWebScrapeService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Safe\file_get_contents;
use function Safe\json_decode;

final class StampWebScrapeServiceTest extends TestCase
{
    public function testParseItemFromInertiaHtml(): void
    {
        $html = file_get_contents(__DIR__.'/../../fixtures/stamp_web/item_detail.html');
        $service = new StampWebScrapeService(new MockHttpClient());
        $item = $service->parseItemFromInertiaHtml($html);

        self::assertSame(3535, $item['id']);
        self::assertSame('Praděd', $item['name']);
        $images = $item['images'] ?? null;
        self::assertIsArray($images);
        self::assertCount(2, $images);
    }

    public function testScrapeWritesJsonAndClassifiesImages(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $detailHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/item_detail.html');
        $lazyJson = file_get_contents(__DIR__.'/../../fixtures/stamp_web/lazy_page1.json');

        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $lazyJson): MockResponse {
            self::assertSame('GET', $method);

            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                return new MockResponse($detailHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }
            if (str_contains($url, '/storage/item_images/medium/current.png')) {
                return new MockResponse('CURRENT-IMG', ['response_headers' => ['content-type' => 'image/png']]);
            }
            if (str_contains($url, '/storage/item_images/medium/archive.png')) {
                return new MockResponse('ARCHIVE-IMG', ['response_headers' => ['content-type' => 'image/png']]);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $service = new StampWebScrapeService($client);
        $stats = $service->scrape(new StampWebScrapeOptions(
            outputDir: $outputDir,
            delayDetailMs: 0,
            delayListMs: 0,
            delayImageMs: 0,
            batchPauseMs: 0,
            bucketPauseMs: 0,
            countryId: 1,
            typeId: 0,
        ));

        self::assertSame(1, $stats['discovered']);
        self::assertSame(1, $stats['scraped']);
        self::assertSame(0, $stats['skipped']);
        self::assertSame(0, $stats['failed']);
        self::assertSame(2, $stats['images']);

        $jsonPath = $outputDir.'/stamps/3535/stamp.json';
        self::assertFileExists($jsonPath);
        $payload = json_decode(file_get_contents($jsonPath), true);
        self::assertIsArray($payload);
        self::assertSame(3535, $payload['id']);
        self::assertArrayHasKey('_scrape', $payload);
        $scrapeMeta = $payload['_scrape'];
        self::assertIsArray($scrapeMeta);
        $imageFiles = $scrapeMeta['imageFiles'] ?? null;
        self::assertIsArray($imageFiles);
        self::assertCount(2, $imageFiles);

        $currentPath = $outputDir.'/stamps/3535/images/current/current.png';
        $archivePath = $outputDir.'/stamps/3535/images/archive/archive.png';
        self::assertFileExists($currentPath);
        self::assertFileExists($archivePath);
        self::assertSame('CURRENT-IMG', file_get_contents($currentPath));
        self::assertSame('ARCHIVE-IMG', file_get_contents($archivePath));

        self::assertFileExists($outputDir.'/index.jsonl');
        self::assertFileExists($outputDir.'/scrape-meta.json');

        // Resume skips existing stamp.json
        $stats2 = $service->scrape(new StampWebScrapeOptions(
            outputDir: $outputDir,
            delayDetailMs: 0,
            delayListMs: 0,
            delayImageMs: 0,
            batchPauseMs: 0,
            bucketPauseMs: 0,
            countryId: 1,
            typeId: 0,
        ));
        self::assertSame(1, $stats2['skipped']);
        self::assertSame(0, $stats2['scraped']);
    }

    public function testParseInertiaPageWithoutDataPageThrows(): void
    {
        $service = new StampWebScrapeService(new MockHttpClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('data-page');
        $service->parseInertiaPage('<html><body>nope</body></html>');
    }

    public function testRequestPassesTimeoutOptions(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $lazyEmpty = json_encode([
            'items' => [],
            'totalRecords' => 0,
            'itemsPerPage' => 100,
            'currentPage' => 1,
        ], \JSON_THROW_ON_ERROR);

        $seenTimeout = null;
        $seenMaxDuration = null;
        $call = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($listHtml, $lazyEmpty, &$seenTimeout, &$seenMaxDuration, &$call): MockResponse {
            ++$call;
            if (1 === $call) {
                $seenTimeout = $options['timeout'] ?? null;
                $seenMaxDuration = $options['max_duration'] ?? null;

                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            return new MockResponse($lazyEmpty, ['response_headers' => ['content-type' => 'application/json']]);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        (new StampWebScrapeService($client))->scrape(new StampWebScrapeOptions(
            outputDir: $outputDir,
            delayDetailMs: 0,
            delayListMs: 0,
            delayImageMs: 0,
            batchPauseMs: 0,
            bucketPauseMs: 0,
            countryId: 1,
            typeId: 0,
            timeoutSec: 12.5,
        ));

        self::assertSame(12.5, $seenTimeout);
        self::assertSame(12.5, $seenMaxDuration);
    }

    public function testRetriesTransientHttpErrorsThenSucceeds(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $detailHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/item_detail.html');
        $lazyJson = file_get_contents(__DIR__.'/../../fixtures/stamp_web/lazy_page1.json');
        $detailAttempts = 0;

        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $lazyJson, &$detailAttempts): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                ++$detailAttempts;
                if ($detailAttempts < 3) {
                    return new MockResponse('boom', ['http_code' => 502]);
                }

                return new MockResponse($detailHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }
            if (str_contains($url, '/storage/item_images/medium/')) {
                return new MockResponse('IMG', ['response_headers' => ['content-type' => 'image/png']]);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $stats = (new StampWebScrapeService($client))->scrape(new StampWebScrapeOptions(
            outputDir: $outputDir,
            delayDetailMs: 0,
            delayListMs: 0,
            delayImageMs: 0,
            batchPauseMs: 0,
            bucketPauseMs: 0,
            countryId: 1,
            typeId: 0,
            retries: 3,
        ));

        self::assertSame(1, $stats['scraped']);
        self::assertSame(3, $detailAttempts);
    }

    public function testExhaustedRetriesMarkStampFailed(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $lazyJson = file_get_contents(__DIR__.'/../../fixtures/stamp_web/lazy_page1.json');

        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $lazyJson): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                return new MockResponse('boom', ['http_code' => 502]);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $stats = (new StampWebScrapeService($client))->scrape(new StampWebScrapeOptions(
            outputDir: $outputDir,
            delayDetailMs: 0,
            delayListMs: 0,
            delayImageMs: 0,
            batchPauseMs: 0,
            bucketPauseMs: 0,
            countryId: 1,
            typeId: 0,
            retries: 1,
        ));

        self::assertSame(0, $stats['scraped']);
        self::assertSame(1, $stats['failed']);
    }
}

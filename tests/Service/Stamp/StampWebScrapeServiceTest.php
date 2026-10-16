<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Service\Stamp\StampWebScrapeOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Safe\file_get_contents;
use function Safe\json_decode;
use function Safe\unlink;

final class StampWebScrapeServiceTest extends TestCase
{
    use StampWebScrapeTestTrait;

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
        $service = $this->service($client);
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
        $index = file_get_contents($outputDir.'/index.jsonl');
        self::assertSame(1, substr_count($index, "\n"));
        self::assertFileExists($outputDir.'/scrape-meta.json');
        $meta = json_decode(file_get_contents($outputDir.'/scrape-meta.json'), true);
        self::assertIsArray($meta);
        self::assertIsString($meta['startedAt'] ?? null);
        self::assertIsString($meta['finishedAt'] ?? null);
        self::assertSame(1, $meta['discovered'] ?? null);
        self::assertIsArray($meta['options'] ?? null);

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
        self::assertSame(1, substr_count(file_get_contents($outputDir.'/index.jsonl'), "\n"));
    }

    public function testScrapeSanitizesImageSrcPathTraversal(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $lazyJson = file_get_contents(__DIR__.'/../../fixtures/stamp_web/lazy_page1.json');
        $detailHtml = str_replace(
            '&quot;src&quot;: &quot;current.png&quot;',
            '&quot;src&quot;: &quot;../../outside.png&quot;',
            file_get_contents(__DIR__.'/../../fixtures/stamp_web/item_detail.html'),
        );
        $detailHtml = str_replace(
            '&quot;src&quot;: &quot;archive.png&quot;',
            '&quot;src&quot;: &quot;nested/../evil.png&quot;',
            $detailHtml,
        );

        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $lazyJson): MockResponse {
            self::assertSame('GET', $method);

            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                return new MockResponse($detailHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }
            if (str_contains($url, '/storage/item_images/medium/outside.png')) {
                return new MockResponse('OUTSIDE', ['response_headers' => ['content-type' => 'image/png']]);
            }
            if (str_contains($url, '/storage/item_images/medium/evil.png')) {
                return new MockResponse('EVIL', ['response_headers' => ['content-type' => 'image/png']]);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $stampDir = $outputDir.'/stamps/3535';
        $stats = $this->service($client)->scrape(new StampWebScrapeOptions(
            outputDir: $outputDir,
            delayDetailMs: 0,
            delayListMs: 0,
            delayImageMs: 0,
            batchPauseMs: 0,
            bucketPauseMs: 0,
            countryId: 1,
            typeId: 0,
        ));

        self::assertSame(0, $stats['failed']);
        self::assertSame(2, $stats['images']);
        self::assertFileExists($stampDir.'/images/current/outside.png');
        self::assertFileExists($stampDir.'/images/archive/evil.png');
        self::assertFileDoesNotExist($outputDir.'/outside.png');
        self::assertFileDoesNotExist($outputDir.'/stamps/outside.png');
        self::assertSame('OUTSIDE', file_get_contents($stampDir.'/images/current/outside.png'));
        self::assertSame('EVIL', file_get_contents($stampDir.'/images/archive/evil.png'));
    }

    public function testUnsafeImageNamesAreSkippedAndNullBytesStripped(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $lazyJson = file_get_contents(__DIR__.'/../../fixtures/stamp_web/lazy_page1.json');
        $detailHtml = str_replace(
            '&quot;src&quot;: &quot;current.png&quot;',
            '&quot;src&quot;: &quot;..&quot;',
            file_get_contents(__DIR__.'/../../fixtures/stamp_web/item_detail.html'),
        );
        $detailHtml = str_replace(
            '&quot;src&quot;: &quot;archive.png&quot;',
            '&quot;src&quot;: &quot;evil\\u0000.png&quot;',
            $detailHtml,
        );

        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $lazyJson): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                return new MockResponse($detailHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }
            if (str_contains($url, '/storage/item_images/medium/')) {
                self::assertStringEndsWith('/medium/evil.png', $url);
                self::assertStringNotContainsString('%00', $url);

                return new MockResponse('EVIL', ['response_headers' => ['content-type' => 'image/png']]);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $stampDir = $outputDir.'/stamps/3535';
        $stats = $this->service($client)->scrape($this->quietOptions($outputDir));

        self::assertSame(0, $stats['failed']);
        self::assertSame(1, $stats['images']);
        self::assertFileDoesNotExist($stampDir.'/images/current');
        self::assertFileExists($stampDir.'/images/archive/evil.png');
        self::assertSame('EVIL', file_get_contents($stampDir.'/images/archive/evil.png'));
        self::assertFileDoesNotExist($outputDir.'/evil.png');
    }

    public function testForceRescrapesAnExistingStamp(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $detailHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/item_detail.html');
        $lazyJson = file_get_contents(__DIR__.'/../../fixtures/stamp_web/lazy_page1.json');
        $detailFetches = 0;

        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $lazyJson, &$detailFetches): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                ++$detailFetches;

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
        $service = $this->service($client);
        $service->scrape($this->quietOptions($outputDir, skipImages: true));
        $stats = $service->scrape($this->quietOptions($outputDir, skipImages: true, force: true));

        self::assertSame(2, $detailFetches);
        self::assertSame(1, $stats['scraped']);
        self::assertSame(0, $stats['skipped']);
        self::assertSame(0, $stats['failed']);
    }

    public function testExistingImagesAreNotRedownloadedWhenStampJsonIsMissing(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $detailHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/item_detail.html');
        $lazyJson = file_get_contents(__DIR__.'/../../fixtures/stamp_web/lazy_page1.json');

        $firstClient = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $lazyJson): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                return new MockResponse($detailHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }
            if (str_contains($url, '/storage/item_images/medium/')) {
                return new MockResponse('ORIGINAL', ['response_headers' => ['content-type' => 'image/png']]);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $stampDir = $outputDir.'/stamps/3535';
        $this->service($firstClient)->scrape($this->quietOptions($outputDir));

        $currentPath = $stampDir.'/images/current/current.png';
        $archivePath = $stampDir.'/images/archive/archive.png';
        self::assertSame('ORIGINAL', file_get_contents($currentPath));
        unlink($stampDir.'/stamp.json');

        $resumeClient = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $lazyJson): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                return new MockResponse($detailHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }
            if (str_contains($url, '/storage/item_images/medium/')) {
                self::fail('Cached images must not be downloaded again: '.$url);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $stats = $this->service($resumeClient)->scrape($this->quietOptions($outputDir));

        self::assertSame(1, $stats['scraped']);
        self::assertSame(0, $stats['failed']);
        self::assertSame(2, $stats['images']);
        self::assertFileExists($stampDir.'/stamp.json');
        self::assertSame('ORIGINAL', file_get_contents($currentPath));
        self::assertSame('ORIGINAL', file_get_contents($archivePath));
    }

    public function testOneBadDetailDoesNotStopTheNextStamp(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $detailHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/item_detail.html');
        $lazyJson = json_encode([
            'items' => [
                ['id' => 10, 'no' => 1, 'name' => 'Broken'],
                ['id' => 11, 'no' => 2, 'name' => 'Kept'],
            ],
            'totalRecords' => 2,
            'itemsPerPage' => 100,
            'currentPage' => 1,
        ], \JSON_THROW_ON_ERROR);

        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $lazyJson): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/10')) {
                return new MockResponse('<html><body>no inertia page</body></html>');
            }
            if (str_contains($url, '/items/11')) {
                return new MockResponse($detailHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }
            if (str_contains($url, '/storage/item_images/')) {
                self::fail('skipImages must not download images: '.$url);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $stats = $this->service($client)->scrape($this->quietOptions($outputDir, skipImages: true));

        self::assertSame(2, $stats['discovered']);
        self::assertSame(1, $stats['scraped']);
        self::assertSame(1, $stats['failed']);
        self::assertSame(0, $stats['skipped']);
        self::assertFileDoesNotExist($outputDir.'/stamps/10/stamp.json');
        self::assertFileExists($outputDir.'/stamps/11/stamp.json');

        $index = file_get_contents($outputDir.'/index.jsonl');
        self::assertSame(1, substr_count($index, "\n"));
        $row = json_decode(trim($index), true);
        self::assertIsArray($row);
        self::assertSame(11, $row['id'] ?? null);
        self::assertSame(1, $row['countryId'] ?? null);
        self::assertSame(0, $row['typeId'] ?? null);
        self::assertSame('TZ', $row['type'] ?? null);
        self::assertSame('stamps/11/stamp.json', $row['path'] ?? null);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Service\Stamp\StampWebScrapeOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Safe\file_get_contents;
use function Safe\parse_url;
use function Safe\preg_match;

final class StampWebScrapeDiscoveryTest extends TestCase
{
    use StampWebScrapeTestTrait;

    public function testDiscoveryPaginatesDedupesAndHonorsLimit(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $detailHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/item_detail.html');
        $page1 = json_encode([
            'items' => [
                ['id' => 10, 'no' => 1, 'name' => 'A'],
                ['id' => 11, 'no' => 2, 'name' => 'B'],
            ],
            'totalRecords' => 3,
            'itemsPerPage' => 2,
            'currentPage' => 1,
        ], \JSON_THROW_ON_ERROR);
        $page2 = json_encode([
            'items' => [
                ['id' => 11, 'no' => 2, 'name' => 'B'],
                ['id' => 12, 'no' => 3, 'name' => 'C'],
            ],
            'totalRecords' => 3,
            'itemsPerPage' => 2,
            'currentPage' => 2,
        ], \JSON_THROW_ON_ERROR);

        $lazyPages = [];
        $detailIds = [];
        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $page1, $page2, &$lazyPages, &$detailIds): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                $query = parse_url($url, \PHP_URL_QUERY);
                self::assertIsString($query);
                parse_str($query, $params);
                $page = $params['page'] ?? null;
                self::assertIsScalar($page);
                $pageNumber = (int) $page;
                $lazyPages[] = $pageNumber;
                if (1 === $pageNumber) {
                    return new MockResponse($page1, ['response_headers' => ['content-type' => 'application/json']]);
                }
                if (2 === $pageNumber) {
                    return new MockResponse($page2, ['response_headers' => ['content-type' => 'application/json']]);
                }

                self::fail('Unexpected lazy page: '.$pageNumber);
            }
            if (1 === preg_match('#/items/(\d+)$#', $url, $matches) && isset($matches[1])) {
                $detailIds[] = (int) $matches[1];

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
        $stats = $this->service($client)->scrape($this->quietOptions($outputDir, limit: 2, skipImages: true));

        self::assertSame([1, 2], $lazyPages);
        self::assertSame([10, 11], $detailIds);
        self::assertSame(2, $stats['discovered']);
        self::assertSame(2, $stats['scraped']);
        self::assertSame(0, $stats['failed']);
        self::assertSame(0, $stats['images']);
        self::assertFileExists($outputDir.'/stamps/10/stamp.json');
        self::assertFileExists($outputDir.'/stamps/11/stamp.json');
        self::assertFileDoesNotExist($outputDir.'/stamps/12/stamp.json');
    }

    public function testDiscoveryStopsWhenALaterPageIsEmpty(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $detailHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/item_detail.html');
        $page1 = json_encode([
            'items' => [
                ['id' => 10, 'no' => 1, 'name' => 'A'],
            ],
            'totalRecords' => 500,
            'itemsPerPage' => 1,
            'currentPage' => 1,
        ], \JSON_THROW_ON_ERROR);
        $emptyPage = json_encode([
            'items' => [],
            'totalRecords' => 500,
            'itemsPerPage' => 1,
            'currentPage' => 2,
        ], \JSON_THROW_ON_ERROR);

        $lazyPages = [];
        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $page1, $emptyPage, &$lazyPages): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                $lazyPages[] = $this->lazyPageNumber($url);
                $page = $lazyPages[\count($lazyPages) - 1];
                if (1 === $page) {
                    return new MockResponse($page1, ['response_headers' => ['content-type' => 'application/json']]);
                }
                if (2 === $page) {
                    return new MockResponse($emptyPage, ['response_headers' => ['content-type' => 'application/json']]);
                }

                self::fail('An empty page must stop discovery. Unexpected page: '.$page);
            }
            if (1 === preg_match('#/items/(\d+)$#', $url)) {
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
        $stats = $this->service($client)->scrape($this->quietOptions($outputDir, limit: null, skipImages: true));

        self::assertSame([1, 2], $lazyPages);
        self::assertSame(1, $stats['discovered']);
        self::assertSame(1, $stats['scraped']);
        self::assertSame(0, $stats['failed']);
        self::assertFileExists($outputDir.'/stamps/10/stamp.json');
    }

    public function testDiscoveryStopsWhenPageSizeIsZero(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $detailHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/item_detail.html');
        $page = json_encode([
            'items' => [
                ['id' => 10, 'no' => 1, 'name' => 'A'],
            ],
            'totalRecords' => 500,
            'itemsPerPage' => 0,
            'currentPage' => 1,
        ], \JSON_THROW_ON_ERROR);

        $lazyPages = [];
        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $page, &$lazyPages): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                $pageNumber = $this->lazyPageNumber($url);
                $lazyPages[] = $pageNumber;
                if (1 !== $pageNumber) {
                    self::fail('A zero page size must stop discovery. Unexpected page: '.$pageNumber);
                }

                return new MockResponse($page, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (1 === preg_match('#/items/(\d+)$#', $url)) {
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
        $stats = $this->service($client)->scrape($this->quietOptions($outputDir, limit: null, skipImages: true));

        self::assertSame([1], $lazyPages);
        self::assertSame(1, $stats['discovered']);
        self::assertSame(1, $stats['scraped']);
        self::assertSame(0, $stats['failed']);
        self::assertFileExists($outputDir.'/stamps/10/stamp.json');
    }

    public function testUnmatchedCountryFilterAbortsBeforeDiscovery(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                self::fail('Discovery must not start when no country matches.');
            }
            if (str_contains($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No countries matched the scrape filters.');

        $this->service($client)->scrape($this->quietOptions($outputDir, countryId: 99));
    }

    public function testUnmatchedTypeFilterAbortsBeforeDiscovery(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                self::fail('Discovery must not start when no type matches.');
            }
            if (str_contains($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No types matched the scrape filters.');

        $this->service($client)->scrape($this->quietOptions($outputDir, typeId: 99));
    }

    public function testDuplicateIdAcrossTypeBucketsIsScrapedOnce(): void
    {
        $listHtml = $this->catalogListHtml([
            ['id' => 0, 'name' => 'Tourist Stamp', 'short' => 'TZ'],
            ['id' => 1, 'name' => 'Annual', 'short' => 'VTZ'],
        ]);
        $detailHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/item_detail.html');
        $lazyJson = json_encode([
            'items' => [
                ['id' => 21, 'no' => 1, 'name' => 'Shared'],
            ],
            'totalRecords' => 1,
            'itemsPerPage' => 100,
            'currentPage' => 1,
        ], \JSON_THROW_ON_ERROR);

        $types = [];
        $detailIds = [];
        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $lazyJson, &$types, &$detailIds): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                $query = parse_url($url, \PHP_URL_QUERY);
                self::assertIsString($query);
                parse_str($query, $params);
                $type = $params['type'] ?? null;
                self::assertIsScalar($type);
                $types[] = (int) $type;

                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (1 === preg_match('#/items/(\d+)$#', $url, $matches) && isset($matches[1])) {
                $detailIds[] = (int) $matches[1];

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
        $stats = $this->service($client)->scrape(new StampWebScrapeOptions(
            outputDir: $outputDir,
            delayDetailMs: 0,
            delayListMs: 0,
            delayImageMs: 0,
            batchPauseMs: 0,
            bucketPauseMs: 0,
            countryId: 1,
            skipImages: true,
            retries: 0,
            rateLimitRetries: 0,
        ));

        self::assertSame([0, 1], $types);
        self::assertSame([21], $detailIds);
        self::assertSame(1, $stats['discovered']);
        self::assertSame(1, $stats['scraped']);
        self::assertSame(0, $stats['failed']);
        self::assertSame(1, substr_count(file_get_contents($outputDir.'/index.jsonl'), "\n"));
        self::assertFileExists($outputDir.'/stamps/21/stamp.json');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Service\Stamp\StampWebScrapeOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Safe\file_get_contents;

final class StampWebScrapeRetryTest extends TestCase
{
    use StampWebScrapeTestTrait;

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
        $this->service($client)->scrape(new StampWebScrapeOptions(
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
        $stats = $this->service($client)->scrape(new StampWebScrapeOptions(
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
        $stats = $this->service($client)->scrape(new StampWebScrapeOptions(
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

    public function testClientErrorsAreNotRetried(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $lazyJson = file_get_contents(__DIR__.'/../../fixtures/stamp_web/lazy_page1.json');
        $detailAttempts = 0;

        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $lazyJson, &$detailAttempts): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                ++$detailAttempts;

                return new MockResponse('missing', ['http_code' => 404]);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $stats = $this->service($client)->scrape($this->quietOptions($outputDir, retries: 3));

        self::assertSame(1, $detailAttempts);
        self::assertSame(0, $stats['scraped']);
        self::assertSame(1, $stats['failed']);
        self::assertFileDoesNotExist($outputDir.'/stamps/3535/stamp.json');
    }

    public function testRateLimitResponsesAreRetriedThenSucceed(): void
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
                    return new MockResponse('slow down', [
                        'http_code' => 429,
                        'response_headers' => ['retry-after' => '30'],
                    ]);
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
        $stats = $this->service($client)->scrape($this->quietOptions($outputDir));

        self::assertSame(3, $detailAttempts);
        self::assertSame(1, $stats['scraped']);
        self::assertSame(0, $stats['failed']);
    }

    public function testExhaustedRateLimitRetriesMarkStampFailed(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $lazyJson = file_get_contents(__DIR__.'/../../fixtures/stamp_web/lazy_page1.json');
        $detailAttempts = 0;

        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $lazyJson, &$detailAttempts): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                ++$detailAttempts;

                return new MockResponse('slow down', ['http_code' => 429]);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $stats = $this->service($client)->scrape($this->quietOptions($outputDir, rateLimitRetries: 1));

        self::assertSame(2, $detailAttempts);
        self::assertSame(0, $stats['scraped']);
        self::assertSame(1, $stats['failed']);
    }

    public function testServiceUnavailableUsesTheRateLimitBudgetNotTransientRetries(): void
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
                if ($detailAttempts < 2) {
                    return new MockResponse('unavailable', ['http_code' => 503]);
                }

                return new MockResponse($detailHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $stats = $this->service($client)->scrape($this->quietOptions(
            $outputDir,
            retries: 0,
            rateLimitRetries: 1,
            skipImages: true,
        ));

        self::assertSame(2, $detailAttempts);
        self::assertSame(1, $stats['scraped']);
        self::assertSame(0, $stats['failed']);
    }

    public function testServiceUnavailableDoesNotUseTheTransientRetryBudget(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../../fixtures/stamp_web/items_list.html');
        $lazyJson = file_get_contents(__DIR__.'/../../fixtures/stamp_web/lazy_page1.json');
        $detailAttempts = 0;

        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $lazyJson, &$detailAttempts): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                ++$detailAttempts;

                return new MockResponse('unavailable', ['http_code' => 503]);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $stats = $this->service($client)->scrape($this->quietOptions(
            $outputDir,
            retries: 3,
            rateLimitRetries: 0,
            skipImages: true,
        ));

        self::assertSame(1, $detailAttempts);
        self::assertSame(0, $stats['scraped']);
        self::assertSame(1, $stats['failed']);
    }

    public function testTransportErrorsAreRetriedThenSucceed(): void
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
                if ($detailAttempts < 2) {
                    return new MockResponse('', ['error' => 'idle timeout']);
                }

                return new MockResponse($detailHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $outputDir = sys_get_temp_dir().'/znamky-scrape-'.bin2hex(random_bytes(4));
        $stats = $this->service($client)->scrape($this->quietOptions(
            $outputDir,
            retries: 1,
            skipImages: true,
        ));

        self::assertSame(2, $detailAttempts);
        self::assertSame(1, $stats['scraped']);
        self::assertSame(0, $stats['failed']);
        self::assertFileExists($outputDir.'/stamps/3535/stamp.json');
    }
}

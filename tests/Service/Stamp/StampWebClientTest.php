<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Service\Stamp\StampWebClient;
use App\Service\Stamp\StampWebScrapeOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StampWebClientTest extends TestCase
{
    public function testRateLimitWaitsForRetryAfterSeconds(): void
    {
        $clock = new MockClock('2026-01-01 00:00:00');
        $client = new StampWebClient(new MockHttpClient([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 7']]),
            new MockResponse('ok'),
        ]), $clock);

        self::assertSame('ok', $client->get('https://example.test/', new StampWebScrapeOptions(outputDir: '/unused')));
        self::assertSame('2026-01-01 00:00:07', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function testRateLimitWithoutRetryAfterBacksOffExponentially(): void
    {
        $clock = new MockClock('2026-01-01 00:00:00');
        $client = new StampWebClient(new MockHttpClient([
            new MockResponse('', ['http_code' => 429]),
            new MockResponse('', ['http_code' => 429]),
            new MockResponse('ok'),
        ]), $clock);

        self::assertSame('ok', $client->get('https://example.test/', new StampWebScrapeOptions(outputDir: '/unused')));
        self::assertSame('2026-01-01 00:01:30', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function testRetryAfterZeroStillWaitsOneSecond(): void
    {
        $clock = new MockClock('2026-01-01 00:00:00');
        $client = new StampWebClient(new MockHttpClient([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 0']]),
            new MockResponse('ok'),
        ]), $clock);

        $client->get('https://example.test/', $this->options());
        self::assertSame('2026-01-01 00:00:01', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function testServerErrorIsRetriedAfterTwoToFiveSeconds(): void
    {
        $clock = new MockClock('2026-01-01 00:00:00');
        $start = $clock->now();
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('ok'),
        ]);

        self::assertSame('ok', (new StampWebClient($http, $clock))->get('https://example.test/', $this->options(retries: 1)));
        self::assertSame(2, $http->getRequestsCount());
        $waited = (float) $clock->now()->format('U.u') - (float) $start->format('U.u');
        self::assertGreaterThanOrEqual(2.0, $waited);
        self::assertLessThanOrEqual(5.0, $waited);
    }

    public function testServerErrorFailsOnceRetriesAreSpent(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 500]),
        ]);

        try {
            (new StampWebClient($http, new MockClock()))->get('https://example.test/', $this->options(retries: 1));
            self::fail('Expected exception.');
        } catch (\RuntimeException $e) {
            self::assertSame('HTTP 500 for https://example.test/ after 1 retries.', $e->getMessage());
        }
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testRedirectStatusIsNotAccepted(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 300])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 300 for https://example.test/.');
        (new StampWebClient($http, new MockClock()))->get('https://example.test/', $this->options());
    }

    public function testTransportErrorIsRetriedThenReported(): void
    {
        $clock = new MockClock('2026-01-01 00:00:00');
        $http = new MockHttpClient([
            new MockResponse('', ['error' => 'boom']),
            new MockResponse('', ['error' => 'boom']),
        ]);

        try {
            (new StampWebClient($http, $clock))->get('https://example.test/', $this->options(retries: 1));
            self::fail('Expected exception.');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Transport error for https://example.test/ after 1 retries:', $e->getMessage());
        }
        self::assertSame(2, $http->getRequestsCount());
        self::assertGreaterThanOrEqual('2026-01-01 00:00:02', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function testSendsScraperUserAgent(): void
    {
        $response = new MockResponse('ok');
        (new StampWebClient(new MockHttpClient($response), new MockClock()))->get('https://example.test/', $this->options());

        $headers = $response->getRequestOptions()['headers'] ?? null;
        self::assertIsArray($headers);
        self::assertContains('User-Agent: Mozilla/5.0 (compatible; ZnamkyScraper/1.0; +https://github.com/calvera/znamky)', $headers);
    }

    private function options(int $retries = 3): StampWebScrapeOptions
    {
        return new StampWebScrapeOptions(outputDir: '/unused', retries: $retries);
    }
}

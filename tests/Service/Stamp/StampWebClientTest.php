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
}

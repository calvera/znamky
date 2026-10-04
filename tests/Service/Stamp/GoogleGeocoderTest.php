<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Service\Stamp\GoogleGeocoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleGeocoderTest extends TestCase
{
    public function testGeocodeSuccess(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringContainsString('maps.googleapis.com/maps/api/geocode/json', $url);
            $query = $options['query'] ?? null;
            if (!\is_array($query)) {
                self::fail('Expected query options array.');
            }
            self::assertSame('Praděd, Moravskoslezský kraj, Czech Republic', $query['address'] ?? null);
            self::assertSame('test-key', $query['key'] ?? null);

            return new MockResponse(json_encode([
                'status' => 'OK',
                'results' => [
                    ['geometry' => ['location' => ['lat' => 50.0831, 'lng' => 17.2308]]],
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        $geocoder = new GoogleGeocoder($client, 'test-key');
        $result = $geocoder->geocode('Praděd, Moravskoslezský kraj, Czech Republic');

        self::assertSame(['lat' => 50.0831, 'lng' => 17.2308], $result);
    }

    public function testGeocodeZeroResultsReturnsNull(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['status' => 'ZERO_RESULTS', 'results' => []], \JSON_THROW_ON_ERROR)),
        ]);

        $geocoder = new GoogleGeocoder($client, 'test-key');

        self::assertNull($geocoder->geocode('Nowhere-land'));
    }

    public function testGeocodeRequestDeniedThrows(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'status' => 'REQUEST_DENIED',
                'error_message' => 'The provided API key is invalid.',
            ], \JSON_THROW_ON_ERROR)),
        ]);

        $geocoder = new GoogleGeocoder($client, 'bad-key');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REQUEST_DENIED');
        $geocoder->geocode('Praděd');
    }

    public function testMissingApiKeyThrows(): void
    {
        $geocoder = new GoogleGeocoder(new MockHttpClient(), '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GOOGLE_MAPS_API_KEY');
        $geocoder->geocode('Praděd');
    }

    public function testBlankAddressReturnsNullWithoutRequest(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            self::fail('Blank addresses must not call Google.');
        });

        self::assertNull((new GoogleGeocoder($client, 'test-key'))->geocode('   '));
    }

    public function testHttpErrorThrows(): void
    {
        $client = new MockHttpClient([
            new MockResponse('unavailable', ['http_code' => 503]),
        ]);

        $geocoder = new GoogleGeocoder($client, 'test-key');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('status 503');
        $geocoder->geocode('Praděd');
    }

    public function testOkResponseWithoutCoordinatesReturnsNull(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'status' => 'OK',
                'results' => [['geometry' => ['location' => []]]],
            ], \JSON_THROW_ON_ERROR)),
        ]);

        self::assertNull((new GoogleGeocoder($client, 'test-key'))->geocode('Praděd'));
    }
}

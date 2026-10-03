<?php

declare(strict_types=1);

namespace App\Service\Stamp;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias(GeocoderInterface::class)]
final class GoogleGeocoder implements GeocoderInterface
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(GOOGLE_MAPS_API_KEY)%')]
        private readonly string $apiKey,
    ) {
    }

    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ('' === $address) {
            return null;
        }

        if ('' === $this->apiKey) {
            throw new \RuntimeException('GOOGLE_MAPS_API_KEY is not configured.');
        }

        $response = $this->httpClient->request('GET', self::ENDPOINT, [
            'query' => [
                'address' => $address,
                'key' => $this->apiKey,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Google Geocoding HTTP request failed with status %d.',
                $statusCode,
            ));
        }

        /** @var array{status?: string, error_message?: string, results?: list<array{geometry?: array{location?: array{lat?: float|int, lng?: float|int}}}>} $payload */
        $payload = $response->toArray();
        $status = $payload['status'] ?? 'UNKNOWN';

        if ('REQUEST_DENIED' === $status || 'OVER_QUERY_LIMIT' === $status) {
            throw new \RuntimeException(sprintf(
                'Google Geocoding failed with status %s: %s',
                $status,
                $payload['error_message'] ?? 'no details',
            ));
        }

        if ('OK' !== $status) {
            return null;
        }

        $location = $payload['results'][0]['geometry']['location'] ?? null;
        if (!isset($location['lat'], $location['lng'])) {
            return null;
        }

        return [
            'lat' => (float) $location['lat'],
            'lng' => (float) $location['lng'],
        ];
    }
}

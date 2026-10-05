<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MetricsEndpointTest extends WebTestCase
{
    public function testPrometheusEndpointIsPublicAndExposesHttpMetrics(): void
    {
        $client = static::createClient();
        // in_memory storage is wiped if the kernel reboots between requests
        $client->disableReboot();

        $client->request('GET', '/api/docs');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/metrics/prometheus');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertStringStartsWith('text/plain', (string) $client->getResponse()->headers->get('Content-Type'));

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('znamky_http_requests_total', $body);
        self::assertStringContainsString('znamky_http_2xx_responses_total', $body);
    }
}

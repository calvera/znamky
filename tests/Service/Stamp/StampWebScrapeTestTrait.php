<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Service\Stamp\InertiaPageParser;
use App\Service\Stamp\StampCatalogDiscovery;
use App\Service\Stamp\StampWebClient;
use App\Service\Stamp\StampWebScrapeOptions;
use App\Service\Stamp\StampWebScrapeService;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

use function Safe\parse_url;

trait StampWebScrapeTestTrait
{
    private function service(MockHttpClient $client): StampWebScrapeService
    {
        $clock = new MockClock();

        $webClient = new StampWebClient($client, $clock);
        $parser = new InertiaPageParser();

        return new StampWebScrapeService($webClient, $parser, new StampCatalogDiscovery($webClient, $parser), $clock);
    }

    private function lazyPageNumber(string $url): int
    {
        $query = parse_url($url, \PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $params);
        $page = $params['page'] ?? null;
        self::assertIsScalar($page);

        return (int) $page;
    }

    private function quietOptions(
        string $outputDir,
        int $retries = 3,
        int $rateLimitRetries = 5,
        ?int $limit = null,
        bool $skipImages = false,
        int $countryId = 1,
        int $typeId = 0,
        bool $force = false,
    ): StampWebScrapeOptions {
        return new StampWebScrapeOptions(
            outputDir: $outputDir,
            delayDetailMs: 0,
            delayListMs: 0,
            delayImageMs: 0,
            batchPauseMs: 0,
            bucketPauseMs: 0,
            countryId: $countryId,
            typeId: $typeId,
            limit: $limit,
            force: $force,
            skipImages: $skipImages,
            retries: $retries,
            rateLimitRetries: $rateLimitRetries,
        );
    }

    /**
     * @param list<array{id: int, name: string, short: string}> $types
     */
    private function catalogListHtml(array $types): string
    {
        $page = [
            'component' => 'Items',
            'props' => [
                'referenceData' => [
                    'countries' => [
                        ['id' => 1, 'name' => 'Czech Republic', 'short' => 'CZ'],
                    ],
                ],
                'filtersData' => [
                    'types' => $types,
                ],
            ],
            'url' => '/items',
        ];
        $encoded = htmlspecialchars(
            json_encode($page, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            \ENT_QUOTES | \ENT_HTML5,
            'UTF-8',
        );

        return '<div id="app" data-page="'.$encoded.'"></div>';
    }
}

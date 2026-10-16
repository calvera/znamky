<?php

declare(strict_types=1);

namespace App\Service\Stamp;

use Webmozart\Assert\Assert;

use function Safe\json_decode;

/**
 * Finds which stamp ids turisticke-znamky.cz lists for the requested countries and types.
 */
final class StampCatalogDiscovery
{
    private const int LAZY_ROWS = 100;

    public function __construct(
        private readonly StampWebClient $client,
        private readonly InertiaPageParser $parser,
    ) {
    }

    /**
     * @return array{
     *     countries: non-empty-list<array{id: int, short?: string, name?: string}>,
     *     types: non-empty-list<array{id: int, short?: string, name?: string}>
     * }
     */
    public function resolveFilters(StampWebScrapeOptions $options): array
    {
        $page = $this->parser->parsePage($this->client->get(StampWebClient::BASE_URL.'/items?country=1&type=0&page=1&rows=5', $options));
        $this->client->pause($options->delayListMs);

        $referenceData = $page['props']['referenceData'] ?? null;
        $filtersData = $page['props']['filtersData'] ?? null;
        Assert::isArray($referenceData, 'Missing referenceData.');
        Assert::isArray($filtersData, 'Missing filtersData.');

        $countriesRaw = $referenceData['countries'] ?? null;
        $typesRaw = $filtersData['types'] ?? null;
        Assert::isArray($countriesRaw, 'Missing referenceData.countries.');
        Assert::isArray($typesRaw, 'Missing filtersData.types.');

        $countries = $this->filterEntries($countriesRaw, $options->countryId, 'Country id must be integerish.');
        $types = $this->filterEntries($typesRaw, $options->typeId, 'Type id must be integerish.');

        Assert::notEmpty($countries, 'No countries matched the scrape filters.');
        Assert::notEmpty($types, 'No types matched the scrape filters.');

        return ['countries' => $countries, 'types' => $types];
    }

    /**
     * Pages through the lazy listing of every country × type bucket, in listing order without duplicates.
     *
     * @param list<array{id: int, short?: string, name?: string}> $countries
     * @param list<array{id: int, short?: string, name?: string}> $types
     *
     * @return list<int>
     */
    public function discoverIds(array $countries, array $types, StampWebScrapeOptions $options, StampWebScrapeListener $listener): array
    {
        $ids = [];
        $seen = [];
        $firstBucket = true;

        foreach ($countries as $country) {
            foreach ($types as $type) {
                if (!$firstBucket) {
                    $this->client->pause($options->bucketPauseMs);
                }
                $firstBucket = false;

                $listener->bucketStarted($country['id'], $type['id']);
                foreach ($this->discoverBucket($country['id'], $type['id'], $options) as $id) {
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Pages through the lazy listing of one country × type bucket and returns its ids in listing order.
     *
     * @return list<int>
     */
    private function discoverBucket(int $countryId, int $typeId, StampWebScrapeOptions $options): array
    {
        $ids = [];
        $page = 1;
        while (true) {
            $query = http_build_query([
                'country' => $countryId,
                'type' => $typeId,
                'sorting' => 'number',
                'page' => $page,
                'rows' => self::LAZY_ROWS,
            ]);
            $payload = json_decode($this->client->get(StampWebClient::BASE_URL.'/items/lazy?'.$query, $options), true);
            $this->client->pause($options->delayListMs);

            Assert::isArray($payload, 'Lazy listing response must be JSON object.');
            $items = $payload['items'] ?? [];
            Assert::isArray($items);

            if ([] === $items) {
                return $ids;
            }

            foreach ($items as $item) {
                Assert::isArray($item);
                $ids[] = $this->requireInt($item['id'] ?? null, 'Lazy item id must be integerish.');
            }

            $total = $this->requireInt($payload['totalRecords'] ?? 0, 'totalRecords must be integerish.');
            $perPage = $this->requireInt($payload['itemsPerPage'] ?? self::LAZY_ROWS, 'itemsPerPage must be integerish.');
            if ($perPage <= 0 || $page * $perPage >= $total) {
                return $ids;
            }
            ++$page;
        }
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<array{id: int, short?: string, name?: string}>
     */
    private function filterEntries(array $raw, ?int $onlyId, string $idMessage): array
    {
        $entries = [];
        foreach ($raw as $row) {
            Assert::isArray($row);
            $id = $this->requireInt($row['id'] ?? null, $idMessage);
            if (null !== $onlyId && $id !== $onlyId) {
                continue;
            }
            $entry = ['id' => $id];
            if (isset($row['short']) && \is_string($row['short'])) {
                $entry['short'] = $row['short'];
            }
            if (isset($row['name']) && \is_string($row['name'])) {
                $entry['name'] = $row['name'];
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    private function requireInt(mixed $value, string $message): int
    {
        Assert::integerish($value, $message);

        return (int) $value;
    }
}

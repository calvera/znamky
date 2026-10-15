<?php

declare(strict_types=1);

namespace App\Service\Stamp;

/**
 * Ignores all scrape progress notifications.
 */
final class NullStampWebScrapeListener implements StampWebScrapeListener
{
    public function filtersResolved(int $countries, int $types): void
    {
    }

    public function bucketStarted(int $countryId, int $typeId): void
    {
    }

    public function stampsDiscovered(int $count): void
    {
    }

    public function stampSkipped(int $id): void
    {
    }

    public function stampScraped(int $id, int $images): void
    {
    }

    public function stampFailed(int $id, string $error): void
    {
    }
}

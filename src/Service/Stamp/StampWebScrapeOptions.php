<?php

declare(strict_types=1);

namespace App\Service\Stamp;

/**
 * Options for scraping turisticke-znamky.cz to local JSON + images.
 */
final readonly class StampWebScrapeOptions
{
    public function __construct(
        public string $outputDir,
        public int $delayDetailMs = 800,
        public int $delayListMs = 400,
        public int $delayImageMs = 150,
        public ?int $countryId = null,
        public ?int $typeId = null,
        public ?int $limit = null,
        public bool $force = false,
        public bool $skipImages = false,
        public int $batchPauseEvery = 50,
        public int $batchPauseMs = 5000,
        public int $bucketPauseMs = 2000,
        public float $timeoutSec = 30.0,
        public int $retries = 3,
        public int $rateLimitRetries = 5,
    ) {
    }
}

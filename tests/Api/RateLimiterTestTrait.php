<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Psr\Cache\CacheItemPoolInterface;

trait RateLimiterTestTrait
{
    private function clearRateLimiters(): void
    {
        /** @var CacheItemPoolInterface $pool */
        $pool = static::getContainer()->get('cache.rate_limiter');
        $pool->clear();
    }
}

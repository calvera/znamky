<?php

declare(strict_types=1);

namespace App\Service\Stamp;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches turisticke-znamky.cz URLs, retrying rate limits (429/503) and transient failures.
 */
final class StampWebClient
{
    public const string BASE_URL = 'https://turisticke-znamky.cz';
    private const string USER_AGENT = 'Mozilla/5.0 (compatible; ZnamkyScraper/1.0; +https://github.com/calvera/znamky)';
    private const int RATE_LIMIT_BACKOFF_START_SEC = 30;
    private const int RATE_LIMIT_BACKOFF_CAP_SEC = 300;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock,
    ) {
    }

    public function get(string $url, StampWebScrapeOptions $options): string
    {
        $rateAttempt = 0;
        $transientAttempt = 0;
        $rateLimitRetries = max(0, $options->rateLimitRetries);
        $retries = max(0, $options->retries);
        $timeout = max(1.0, $options->timeoutSec);

        while (true) {
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => $timeout,
                    'max_duration' => $timeout,
                    'headers' => [
                        'User-Agent' => self::USER_AGENT,
                        'Accept' => 'text/html,application/json,*/*',
                        'X-Requested-With' => 'XMLHttpRequest',
                    ],
                ]);

                $status = $response->getStatusCode();
                if (\in_array($status, [429, 503], true)) {
                    if ($rateAttempt >= $rateLimitRetries) {
                        throw new \RuntimeException(sprintf('HTTP %d for %s after %d rate-limit retries.', $status, $url, $rateLimitRetries));
                    }
                    $this->clock->sleep($this->rateLimitBackoffSeconds($response, $rateAttempt));
                    ++$rateAttempt;
                    continue;
                }

                if ($status >= 500) {
                    if ($transientAttempt >= $retries) {
                        throw new \RuntimeException(sprintf('HTTP %d for %s after %d retries.', $status, $url, $retries));
                    }
                    $this->retryPause();
                    ++$transientAttempt;
                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException(sprintf('HTTP %d for %s.', $status, $url));
                }

                return $response->getContent(false);
            } catch (TransportExceptionInterface $e) {
                if ($transientAttempt >= $retries) {
                    throw new \RuntimeException(sprintf('Transport error for %s after %d retries: %s', $url, $retries, $e->getMessage()), 0, $e);
                }
                $this->retryPause();
                ++$transientAttempt;
            }
        }
    }

    /**
     * Sleeps for $baseMs ±20% jitter; no-op for non-positive values.
     */
    public function pause(int $baseMs): void
    {
        if ($baseMs <= 0) {
            return;
        }

        $jitter = (int) round($baseMs * 0.2);
        $ms = $baseMs + ($jitter > 0 ? random_int(-$jitter, $jitter) : 0);
        if ($ms > 0) {
            $this->clock->sleep($ms / 1000);
        }
    }

    private function rateLimitBackoffSeconds(ResponseInterface $response, int $attempt): int
    {
        $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
        if (\is_string($retryAfter) && ctype_digit($retryAfter)) {
            return max(1, (int) $retryAfter);
        }

        return min(
            self::RATE_LIMIT_BACKOFF_CAP_SEC,
            self::RATE_LIMIT_BACKOFF_START_SEC * (2 ** $attempt),
        );
    }

    private function retryPause(): void
    {
        $this->clock->sleep(random_int(2000, 5000) / 1000);
    }
}

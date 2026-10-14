<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ScrapeWebStampsCommand;
use App\Service\Stamp\StampWebScrapeService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Safe\file_get_contents;
use function Safe\getcwd;
use function Safe\mkdir;
use function Safe\preg_match;
use function Safe\rmdir;
use function Safe\unlink;

final class ScrapeWebStampsCommandTest extends TestCase
{
    public function testRejectsNonIntegerCountryBeforeScraping(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('unused');
        });

        $tester = $this->tester($client, sys_get_temp_dir());

        try {
            $tester->execute([
                '--country' => 'cz',
                '--output' => 'tz-web',
            ]);
            self::fail('A non-integer --country must abort the command.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('--country must be an integer', $e->getMessage());
        }

        self::assertSame(0, $requests);
    }

    public function testRejectsNonIntegerTypeBeforeScraping(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('unused');
        });

        $tester = $this->tester($client, sys_get_temp_dir());

        try {
            $tester->execute([
                '--type' => 'annual',
                '--output' => 'tz-web',
            ]);
            self::fail('A non-integer --type must abort the command.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('--type must be an integer', $e->getMessage());
        }

        self::assertSame(0, $requests);
    }

    public function testRelativeOutputFailureExitAndDiscoveryProgress(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../fixtures/stamp_web/items_list.html');
        $lazyJson = file_get_contents(__DIR__.'/../fixtures/stamp_web/lazy_page1.json');
        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $lazyJson): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                return new MockResponse('missing', ['http_code' => 404]);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $projectDir = $this->projectDir();

        try {
            $tester = $this->tester($client, $projectDir);
            $exit = $tester->execute($this->fastOptions('tz-web'), [
                'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            ]);

            self::assertSame(Command::FAILURE, $exit);
            $display = $tester->getDisplay();
            $resolved = $projectDir.\DIRECTORY_SEPARATOR.'tz-web';
            self::assertStringContainsString($resolved, $display);
            self::assertStringContainsString('Discovering catalog (1 countries, 1 types)...', $display);
            self::assertStringContainsString('failed id=3535', $display);
            self::assertStringContainsString('discovered=1 scraped=0 skipped=0 failed=1 images=0', $display);
            self::assertStringContainsString('Some stamps failed', $display);
            self::assertDirectoryExists($resolved);
            self::assertDirectoryDoesNotExist(getcwd().\DIRECTORY_SEPARATOR.'tz-web');
        } finally {
            $this->removeTree($projectDir);
        }
    }

    public function testRejectsNonIntegerLimitBeforeScraping(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('unused');
        });

        $tester = $this->tester($client, sys_get_temp_dir());

        try {
            $tester->execute([
                '--limit' => 'abc',
                '--output' => 'tz-web',
            ]);
            self::fail('A non-integer --limit must abort the command.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('--limit must be an integer', $e->getMessage());
        }

        self::assertSame(0, $requests);
    }

    public function testNegativeLimitSkipsDetailFetchesAndSucceeds(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../fixtures/stamp_web/items_list.html');
        $lazyJson = file_get_contents(__DIR__.'/../fixtures/stamp_web/lazy_page1.json');
        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $lazyJson): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (1 === preg_match('#/items/\d+$#', $url)) {
                self::fail('A negative limit must not fetch stamp details: '.$url);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $projectDir = $this->projectDir();

        try {
            $tester = $this->tester($client, $projectDir);
            $exit = $tester->execute([
                ...$this->fastOptions('tz-web'),
                '--limit' => '-3',
            ]);

            self::assertSame(Command::SUCCESS, $exit);
            $display = $tester->getDisplay();
            self::assertStringContainsString('discovered=0 scraped=0 skipped=0 failed=0 images=0', $display);
            self::assertStringContainsString('Web scrape finished.', $display);
        } finally {
            $this->removeTree($projectDir);
        }
    }

    public function testLimitZeroSkipsDetailFetchesAndSucceeds(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../fixtures/stamp_web/items_list.html');
        $lazyJson = file_get_contents(__DIR__.'/../fixtures/stamp_web/lazy_page1.json');
        $client = new MockHttpClient(function (string $method, string $url) use ($listHtml, $lazyJson): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (1 === preg_match('#/items/\d+$#', $url)) {
                self::fail('A zero limit must not fetch stamp details: '.$url);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $projectDir = $this->projectDir();

        try {
            $tester = $this->tester($client, $projectDir);
            $exit = $tester->execute([
                ...$this->fastOptions('tz-web'),
                '--limit' => '0',
            ]);

            self::assertSame(Command::SUCCESS, $exit);
            $display = $tester->getDisplay();
            self::assertStringContainsString('Discovering catalog (1 countries, 1 types)...', $display);
            self::assertStringContainsString('discovered=0 scraped=0 skipped=0 failed=0 images=0', $display);
            self::assertStringContainsString('Web scrape finished.', $display);
        } finally {
            $this->removeTree($projectDir);
        }
    }

    public function testDefaultRunDownloadsImagesAndSkipImagesDoesNot(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../fixtures/stamp_web/items_list.html');
        $detailHtml = file_get_contents(__DIR__.'/../fixtures/stamp_web/item_detail.html');
        $lazyJson = file_get_contents(__DIR__.'/../fixtures/stamp_web/lazy_page1.json');
        $imageRequests = 0;
        $downloadClient = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $lazyJson, &$imageRequests): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                return new MockResponse($detailHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }
            if (str_contains($url, '/storage/item_images/medium/')) {
                ++$imageRequests;

                return new MockResponse('IMG', ['response_headers' => ['content-type' => 'image/png']]);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });
        $skipClient = new MockHttpClient(function (string $method, string $url) use ($listHtml, $detailHtml, $lazyJson): MockResponse {
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (str_contains($url, '/items/3535')) {
                return new MockResponse($detailHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }
            if (str_contains($url, '/storage/item_images/')) {
                self::fail('--skip-images must not download images: '.$url);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $projectDir = $this->projectDir();

        try {
            $download = $this->fastOptions('tz-download');
            unset($download['--skip-images']);
            $downloadTester = $this->tester($downloadClient, $projectDir);
            $exit = $downloadTester->execute($download);

            self::assertSame(Command::SUCCESS, $exit);
            self::assertSame(2, $imageRequests);
            self::assertStringContainsString('images=2', $downloadTester->getDisplay());
            $downloaded = $projectDir.\DIRECTORY_SEPARATOR.'tz-download'.\DIRECTORY_SEPARATOR.'stamps'.\DIRECTORY_SEPARATOR.'3535';
            self::assertFileExists($downloaded.\DIRECTORY_SEPARATOR.'images'.\DIRECTORY_SEPARATOR.'current'.\DIRECTORY_SEPARATOR.'current.png');
            self::assertSame('IMG', file_get_contents($downloaded.\DIRECTORY_SEPARATOR.'images'.\DIRECTORY_SEPARATOR.'current'.\DIRECTORY_SEPARATOR.'current.png'));

            $skipTester = $this->tester($skipClient, $projectDir);
            $exit = $skipTester->execute($this->fastOptions('tz-skip'));

            self::assertSame(Command::SUCCESS, $exit);
            self::assertStringContainsString('images=0', $skipTester->getDisplay());
            $skipped = $projectDir.\DIRECTORY_SEPARATOR.'tz-skip'.\DIRECTORY_SEPARATOR.'stamps'.\DIRECTORY_SEPARATOR.'3535';
            self::assertFileExists($skipped.\DIRECTORY_SEPARATOR.'stamp.json');
            self::assertFileDoesNotExist($skipped.\DIRECTORY_SEPARATOR.'images'.\DIRECTORY_SEPARATOR.'current'.\DIRECTORY_SEPARATOR.'current.png');
        } finally {
            $this->removeTree($projectDir);
        }
    }

    public function testTimeoutBelowOneSecondIsSentAsOneSecond(): void
    {
        $listHtml = file_get_contents(__DIR__.'/../fixtures/stamp_web/items_list.html');
        $lazyJson = file_get_contents(__DIR__.'/../fixtures/stamp_web/lazy_page1.json');
        $seenTimeout = null;
        $seenMaxDuration = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($listHtml, $lazyJson, &$seenTimeout, &$seenMaxDuration): MockResponse {
            if (null === $seenTimeout) {
                $seenTimeout = $options['timeout'] ?? null;
                $seenMaxDuration = $options['max_duration'] ?? null;
            }
            if (str_contains($url, '/items/lazy')) {
                return new MockResponse($lazyJson, ['response_headers' => ['content-type' => 'application/json']]);
            }
            if (1 === preg_match('#/items/\d+$#', $url)) {
                self::fail('A zero limit must not fetch stamp details: '.$url);
            }
            if (str_contains($url, '/items?') || str_ends_with($url, '/items')) {
                return new MockResponse($listHtml, ['response_headers' => ['content-type' => 'text/html']]);
            }

            self::fail('Unexpected URL: '.$url);
        });

        $projectDir = $this->projectDir();

        try {
            $tester = $this->tester($client, $projectDir);
            $exit = $tester->execute([
                ...$this->fastOptions('tz-web'),
                '--limit' => '0',
                '--timeout' => '0',
            ]);

            self::assertSame(Command::SUCCESS, $exit);
            self::assertSame(1.0, $seenTimeout);
            self::assertSame(1.0, $seenMaxDuration);
        } finally {
            $this->removeTree($projectDir);
        }
    }

    public function testRejectsNonNumericTimeoutBeforeScraping(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('unused');
        });

        $tester = $this->tester($client, sys_get_temp_dir());

        try {
            $tester->execute([
                '--timeout' => 'fast',
                '--output' => 'tz-web',
            ]);
            self::fail('A non-numeric --timeout must abort the command.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('--timeout must be numeric', $e->getMessage());
        }

        self::assertSame(0, $requests);
    }

    private function tester(MockHttpClient $client, string $projectDir): CommandTester
    {
        $command = new ScrapeWebStampsCommand(new StampWebScrapeService($client), $projectDir);

        return new CommandTester($command);
    }

    /**
     * @return array<string, bool|string>
     */
    private function fastOptions(string $output): array
    {
        return [
            '--output' => $output,
            '--country' => '1',
            '--type' => '0',
            '--delay-detail' => '0',
            '--delay-list' => '0',
            '--delay-image' => '0',
            '--retries' => '0',
            '--rate-limit-retries' => '0',
            '--skip-images' => true,
        ];
    }

    private function projectDir(): string
    {
        $dir = sys_get_temp_dir().'/znamky-scrape-cmd-'.bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $path = $item->getPathname();
            if ($item->isDir()) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

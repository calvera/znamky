<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Stamp\StampWebScrapeOptions;
use App\Service\Stamp\StampWebScrapeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webmozart\Assert\Assert;

use function Safe\ini_set;

#[AsCommand(
    name: 'app:stamps:scrape-web',
    description: 'Scrape turisticke-znamky.cz stamps to JSON + images under data/tz-web/',
)]
final class ScrapeWebStampsCommand
{
    public function __construct(
        private readonly StampWebScrapeService $stampWebScrapeService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Option('Output directory', name: 'output')]
        string $outputDir = 'data/tz-web',
        #[Option('Only this country id (site id)')]
        ?int $country = null,
        #[Option('Only this type id (site id)')]
        ?int $type = null,
        #[Option('Max stamps to scrape (after discovery)')]
        ?int $limit = null,
        #[Option('Re-download existing stamp.json / images')]
        bool $force = false,
        #[Option('Skip image downloads')]
        bool $skipImages = false,
        #[Option('Pause after each detail fetch (ms)')]
        int $delayDetail = 800,
        #[Option('Pause after each list page (ms)')]
        int $delayList = 400,
        #[Option('Pause after each image download (ms)')]
        int $delayImage = 150,
        #[Option('HTTP timeout per request (seconds)')]
        float $timeout = 30.0,
        #[Option('Retries for timeouts / 5xx / transport errors')]
        int $retries = 3,
        #[Option('Retries for HTTP 429/503')]
        int $rateLimitRetries = 5,
    ): int {
        if (\function_exists('ini_set')) {
            ini_set('memory_limit', '512M');
        }

        if (!str_starts_with($outputDir, '/')) {
            $outputDir = $this->projectDir.\DIRECTORY_SEPARATOR.$outputDir;
        }

        $options = new StampWebScrapeOptions(
            outputDir: $outputDir,
            delayDetailMs: max(0, $delayDetail),
            delayListMs: max(0, $delayList),
            delayImageMs: max(0, $delayImage),
            countryId: $country,
            typeId: $type,
            limit: null === $limit ? null : max(0, $limit),
            force: $force,
            skipImages: $skipImages,
            timeoutSec: max(1.0, $timeout),
            retries: max(0, $retries),
            rateLimitRetries: max(0, $rateLimitRetries),
        );

        $io->title('Scraping turisticke-znamky.cz');
        $io->writeln(sprintf('Output: <info>%s</info>', $outputDir));

        $progress = null;
        $onEvent = function (string $event, array $payload) use ($io, $output, &$progress): void {
            if ('filters' === $event) {
                $countries = \is_array($payload['countries'] ?? null) ? $payload['countries'] : [];
                $types = \is_array($payload['types'] ?? null) ? $payload['types'] : [];
                $io->writeln(sprintf('Discovering catalog (%d countries, %d types)...', \count($countries), \count($types)));

                return;
            }

            if ('discovered' === $event) {
                $countRaw = $payload['count'] ?? 0;
                Assert::integerish($countRaw);
                $count = (int) $countRaw;
                $io->writeln(sprintf('Discovered <info>%d</info> stamp ids.', $count));
                if ($count > 0) {
                    $progress = new ProgressBar($output, $count);
                    $progress->start();
                }

                return;
            }

            if (\in_array($event, ['scraped', 'skip', 'failed'], true) && null !== $progress) {
                $progress->advance();
            }

            if ('failed' === $event) {
                $id = $payload['id'] ?? '?';
                $error = $payload['error'] ?? 'unknown';
                $io->writeln(sprintf(
                    '  <error>failed</error> id=%s: %s',
                    $this->stringifyPayloadValue($id, '?'),
                    $this->stringifyPayloadValue($error, 'unknown'),
                ), OutputInterface::VERBOSITY_VERBOSE);
            }

            if ('bucket' === $event && $output->isVerbose()) {
                $io->writeln(sprintf(
                    '  bucket country=%s type=%s',
                    $this->stringifyPayloadValue($payload['countryId'] ?? '?', '?'),
                    $this->stringifyPayloadValue($payload['typeId'] ?? '?', '?'),
                ));
            }
        };

        $stats = $this->stampWebScrapeService->scrape($options, $onEvent);

        if (null !== $progress) {
            $progress->finish();
            $io->newLine(2);
        }

        $io->writeln(sprintf(
            'Done: discovered=%d scraped=%d skipped=%d failed=%d images=%d',
            $stats['discovered'],
            $stats['scraped'],
            $stats['skipped'],
            $stats['failed'],
            $stats['images'],
        ));

        if ($stats['failed'] > 0) {
            $io->warning('Some stamps failed; re-run to retry (resume skips existing stamp.json).');

            return Command::FAILURE;
        }

        $io->success('Web scrape finished.');

        return Command::SUCCESS;
    }

    private function stringifyPayloadValue(mixed $value, string $fallback): string
    {
        if (null === $value) {
            return $fallback;
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return $fallback;
    }
}

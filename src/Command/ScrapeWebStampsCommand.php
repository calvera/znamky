<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Stamp\StampWebScrapeOptions;
use App\Service\Stamp\StampWebScrapeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webmozart\Assert\Assert;

use function Safe\ini_set;

#[AsCommand(
    name: 'app:stamps:scrape-web',
    description: 'Scrape turisticke-znamky.cz stamps to JSON + images under data/tz-web/',
)]
final class ScrapeWebStampsCommand extends Command
{
    public function __construct(
        private readonly StampWebScrapeService $stampWebScrapeService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory', 'data/tz-web')
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'Only this country id (site id)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Only this type id (site id)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max stamps to scrape (after discovery)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-download existing stamp.json / images')
            ->addOption('skip-images', null, InputOption::VALUE_NONE, 'Skip image downloads')
            ->addOption('delay-detail', null, InputOption::VALUE_REQUIRED, 'Pause after each detail fetch (ms)', '800')
            ->addOption('delay-list', null, InputOption::VALUE_REQUIRED, 'Pause after each list page (ms)', '400')
            ->addOption('delay-image', null, InputOption::VALUE_REQUIRED, 'Pause after each image download (ms)', '150')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'HTTP timeout per request (seconds)', '30')
            ->addOption('retries', null, InputOption::VALUE_REQUIRED, 'Retries for timeouts / 5xx / transport errors', '3')
            ->addOption('rate-limit-retries', null, InputOption::VALUE_REQUIRED, 'Retries for HTTP 429/503', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (\function_exists('ini_set')) {
            ini_set('memory_limit', '512M');
        }

        $io = new SymfonyStyle($input, $output);

        $outputOption = Assert::string($input->getOption('output'), '--output must be a string');
        $outputDir = str_starts_with($outputOption, '/')
            ? $outputOption
            : $this->projectDir.\DIRECTORY_SEPARATOR.$outputOption;

        $countryOption = $input->getOption('country');
        $typeOption = $input->getOption('type');
        $limitOption = $input->getOption('limit');

        $countryId = null;
        if (null !== $countryOption && '' !== $countryOption) {
            Assert::integerish($countryOption, '--country must be an integer');
            $countryId = (int) $countryOption;
        }

        $typeId = null;
        if (null !== $typeOption && '' !== $typeOption) {
            Assert::integerish($typeOption, '--type must be an integer');
            $typeId = (int) $typeOption;
        }

        $limit = null;
        if (null !== $limitOption && '' !== $limitOption) {
            Assert::integerish($limitOption, '--limit must be an integer');
            $limit = max(0, (int) $limitOption);
        }

        $delayDetail = $input->getOption('delay-detail');
        $delayList = $input->getOption('delay-list');
        $delayImage = $input->getOption('delay-image');
        $timeoutOption = $input->getOption('timeout');
        $retriesOption = $input->getOption('retries');
        $rateLimitRetriesOption = $input->getOption('rate-limit-retries');
        Assert::integerish($delayDetail, '--delay-detail must be an integer');
        Assert::integerish($delayList, '--delay-list must be an integer');
        Assert::integerish($delayImage, '--delay-image must be an integer');
        Assert::numeric($timeoutOption, '--timeout must be numeric');
        Assert::integerish($retriesOption, '--retries must be an integer');
        Assert::integerish($rateLimitRetriesOption, '--rate-limit-retries must be an integer');

        $options = new StampWebScrapeOptions(
            outputDir: $outputDir,
            delayDetailMs: max(0, (int) $delayDetail),
            delayListMs: max(0, (int) $delayList),
            delayImageMs: max(0, (int) $delayImage),
            countryId: $countryId,
            typeId: $typeId,
            limit: $limit,
            force: true === $input->getOption('force'),
            skipImages: true === $input->getOption('skip-images'),
            timeoutSec: max(1.0, (float) $timeoutOption),
            retries: max(0, (int) $retriesOption),
            rateLimitRetries: max(0, (int) $rateLimitRetriesOption),
        );

        $io->title('Scraping turisticke-znamky.cz');
        $io->writeln(sprintf('Output: <info>%s</info>', $outputDir));

        $progress = null;
        $onEvent = function (string $event, array $payload) use ($io, $output, &$progress): void {
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

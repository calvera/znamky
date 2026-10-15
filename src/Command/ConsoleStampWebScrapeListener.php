<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Stamp\StampWebScrapeListener;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints scrape progress for app:stamps:scrape-web.
 */
final class ConsoleStampWebScrapeListener implements StampWebScrapeListener
{
    private ?ProgressBar $progress = null;

    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly OutputInterface $output,
    ) {
    }

    public function filtersResolved(int $countries, int $types): void
    {
        $this->io->writeln(\sprintf('Discovering catalog (%d countries, %d types)...', $countries, $types));
    }

    public function bucketStarted(int $countryId, int $typeId): void
    {
        $this->io->writeln(\sprintf('  bucket country=%d type=%d', $countryId, $typeId), OutputInterface::VERBOSITY_VERBOSE);
    }

    public function stampsDiscovered(int $count): void
    {
        $this->io->writeln(\sprintf('Discovered <info>%d</info> stamp ids.', $count));
        if ($count > 0) {
            $this->progress = new ProgressBar($this->output, $count);
            $this->progress->start();
        }
    }

    public function stampSkipped(int $id): void
    {
        $this->progress?->advance();
    }

    public function stampScraped(int $id, int $images): void
    {
        $this->progress?->advance();
    }

    public function stampFailed(int $id, string $error): void
    {
        $this->progress?->advance();
        $this->io->writeln(\sprintf('  <error>failed</error> id=%d: %s', $id, $error), OutputInterface::VERBOSITY_VERBOSE);
    }

    /**
     * Completes the progress bar, if one was started.
     */
    public function finish(): void
    {
        if (null === $this->progress) {
            return;
        }

        $this->progress->finish();
        $this->io->newLine(2);
    }
}

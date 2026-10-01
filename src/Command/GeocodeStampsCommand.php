<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Stamp\StampGeocodeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:stamps:geocode',
    description: 'Geocode stamps and places via Google Maps Geocoding API',
)]
final class GeocodeStampsCommand extends Command
{
    private const LOCK_RESOURCE = 'stamps-catalog';

    public function __construct(
        private readonly StampGeocodeService $stampGeocodeService,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Geocode stamps, places, or all', 'all')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-geocode rows that already have coordinates')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Delay in milliseconds between API calls', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = (string) $input->getOption('only');
        $force = (bool) $input->getOption('force');
        $delayMs = max(0, (int) $input->getOption('delay'));

        if (!\in_array($only, ['stamps', 'places', 'all'], true)) {
            $io->error('--only must be one of: stamps, places, all');

            return Command::FAILURE;
        }

        $lock = $this->lockFactory->createLock(self::LOCK_RESOURCE);
        if (!$lock->acquire()) {
            $io->error('Another stamp catalog operation is already running.');

            return Command::FAILURE;
        }

        try {
            $onProgress = function (string $kind, ?int $id, bool $ok) use ($delayMs, $io): void {
                if ($ok) {
                    $io->writeln(sprintf('  <info>✓</info> %s #%d', $kind, $id ?? 0), OutputInterface::VERBOSITY_VERBOSE);
                } else {
                    $io->writeln(sprintf('  <comment>×</comment> %s #%d (no result)', $kind, $id ?? 0), OutputInterface::VERBOSITY_VERBOSE);
                }
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            };

            if (\in_array($only, ['stamps', 'all'], true)) {
                $io->section('Geocoding stamps');
                $stats = $this->stampGeocodeService->geocodeStamps($force, $onProgress);
                $io->writeln(sprintf(
                    'Stamps: %d geocoded, %d skipped, %d failed',
                    $stats['geocoded'],
                    $stats['skipped'],
                    $stats['failed'],
                ));
            }

            if (\in_array($only, ['places', 'all'], true)) {
                $io->section('Geocoding places');
                $stats = $this->stampGeocodeService->geocodePlaces($force, $onProgress);
                $io->writeln(sprintf(
                    'Places: %d geocoded, %d skipped, %d failed',
                    $stats['geocoded'],
                    $stats['skipped'],
                    $stats['failed'],
                ));
            }

            $io->success('Geocoding finished.');

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}

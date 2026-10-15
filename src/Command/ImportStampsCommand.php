<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Stamp\StampImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

use function Safe\ini_set;

#[AsCommand(
    name: 'app:stamps:import',
    description: 'Import tourist stamps from CSV files under data/',
)]
final class ImportStampsCommand
{
    private const string LOCK_RESOURCE = 'stamps-catalog';

    public function __construct(
        private readonly StampImportService $stampImportService,
        private readonly LockFactory $lockFactory,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Directory containing CSV files')]
        string $path = 'data',
        #[Option('Truncate stamps, tags, and places before import')]
        bool $purge = false,
    ): int {
        if (\function_exists('ini_set')) {
            ini_set('memory_limit', '512M');
        }

        $lock = $this->lockFactory->createLock(self::LOCK_RESOURCE);
        if (!$lock->acquire()) {
            $io->error('Another stamp catalog operation is already running.');

            return Command::FAILURE;
        }

        try {
            if (!str_starts_with($path, '/')) {
                $path = $this->projectDir.\DIRECTORY_SEPARATOR.$path;
            }

            if ($purge) {
                $io->warning('Purging existing stamp catalog before import.');
            }

            $result = $this->stampImportService->import($path, $purge);

            $io->success(sprintf(
                'Imported %d stamps from %d file(s) (%d tags, %d places).',
                $result['stamps'],
                $result['files'],
                $result['tags'],
                $result['places'],
            ));

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}

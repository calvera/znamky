<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Stamp\StampImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:stamps:import',
    description: 'Import tourist stamps from CSV files under data/',
)]
final class ImportStampsCommand extends Command
{
    public function __construct(
        private readonly StampImportService $stampImportService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Directory containing CSV files', 'data')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Truncate stamps, tags, and places before import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (\function_exists('ini_set')) {
            ini_set('memory_limit', '512M');
        }

        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getOption('path');
        if (!str_starts_with($path, '/')) {
            $path = $this->projectDir.\DIRECTORY_SEPARATOR.$path;
        }

        $purge = (bool) $input->getOption('purge');
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
    }
}

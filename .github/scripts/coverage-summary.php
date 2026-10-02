<?php

declare(strict_types=1);

namespace Znamky\Ci;

use SimpleXMLElement;

/**
 * Turns a PHPUnit Clover report into the coverage table published on a GitHub Actions run.
 */
final class CoverageSummary
{
    public function fromClover(string $xml, string $projectDir): string
    {
        $document = simplexml_load_string($xml, options: \LIBXML_NONET);
        if (!$document instanceof SimpleXMLElement || !isset($document->project->metrics)) {
            throw new \InvalidArgumentException('Clover report is missing project metrics.');
        }

        $metrics = $document->project->metrics;
        $linesCovered = (int) $metrics['coveredstatements'];
        $linesTotal = (int) $metrics['statements'];
        $methodsCovered = (int) $metrics['coveredmethods'];
        $methodsTotal = (int) $metrics['methods'];
        $branchesCovered = (int) $metrics['coveredconditionals'];
        $branchesTotal = (int) $metrics['conditionals'];

        $classes = $this->classCounts($document->project);

        $rows = [
            $this->metricRow('Lines', $linesCovered, $linesTotal),
            $this->metricRow('Methods', $methodsCovered, $methodsTotal),
            $this->metricRow('Classes', $classes['covered'], $classes['total']),
        ];
        if ($branchesTotal > 0) {
            $rows[] = $this->metricRow('Branches', $branchesCovered, $branchesTotal);
        }

        $markdown = "## Code coverage\n\n";
        $markdown .= "| Metric | Covered | Total | Coverage |\n";
        $markdown .= "| --- | ---: | ---: | ---: |\n";
        $markdown .= implode("\n", $rows)."\n";

        $files = $this->fileRows($document->project, $projectDir);
        if ([] !== $files) {
            $markdown .= "\n### Files\n\n";
            $markdown .= "| File | Lines | Methods | Coverage |\n";
            $markdown .= "| --- | ---: | ---: | ---: |\n";
            $markdown .= implode("\n", $files)."\n";
        }

        return $markdown;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $path = $argv[1] ?? '';
        if ('' === $path || !is_file($path)) {
            fwrite(\STDERR, "Coverage file not found: {$path}\n");

            return 1;
        }

        $xml = file_get_contents($path);
        if (false === $xml) {
            fwrite(\STDERR, "Unable to read coverage file: {$path}\n");

            return 1;
        }

        try {
            $markdown = $this->fromClover($xml, getcwd() ?: \dirname(__DIR__, 2));
        } catch (\InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage()."\n");

            return 1;
        }

        fwrite(\STDOUT, $markdown."\n");

        $summaryPath = getenv('GITHUB_STEP_SUMMARY');
        if (\is_string($summaryPath) && '' !== $summaryPath) {
            file_put_contents($summaryPath, "\n".$markdown."\n", \FILE_APPEND);
        }

        return 0;
    }

    /**
     * @return array{covered: int, total: int}
     */
    private function classCounts(SimpleXMLElement $project): array
    {
        $covered = 0;
        $total = 0;

        foreach ($this->files($project) as $file) {
            foreach ($file->class as $class) {
                $metrics = $class->metrics;
                $statements = (int) $metrics['statements'];
                if ($statements <= 0) {
                    continue;
                }

                ++$total;
                $conditionals = (int) $metrics['conditionals'];
                $fullyCovered = $conditionals > 0
                    ? (int) $metrics['coveredconditionals'] === $conditionals
                    : (int) $metrics['coveredstatements'] === $statements;
                if ($fullyCovered) {
                    ++$covered;
                }
            }
        }

        return ['covered' => $covered, 'total' => $total];
    }

    /**
     * @return list<string>
     */
    private function fileRows(SimpleXMLElement $project, string $projectDir): array
    {
        $prefix = rtrim($projectDir, '/').'/';
        $rows = [];

        foreach ($this->files($project) as $file) {
            $metrics = $file->metrics;
            $linesCovered = (int) $metrics['coveredstatements'];
            $linesTotal = (int) $metrics['statements'];
            $methodsCovered = (int) $metrics['coveredmethods'];
            $methodsTotal = (int) $metrics['methods'];
            $path = (string) $file['name'];
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, \strlen($prefix));
            }

            $rows[] = [
                'path' => $path,
                'percent' => $linesTotal > 0 ? ($linesCovered / $linesTotal) * 100 : null,
                'markdown' => sprintf(
                    '| `%s` | %d/%d | %d/%d | %s |',
                    str_replace('|', '\\|', $path),
                    $linesCovered,
                    $linesTotal,
                    $methodsCovered,
                    $methodsTotal,
                    $this->percent($linesCovered, $linesTotal),
                ),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $leftPercent = $left['percent'] ?? \PHP_FLOAT_MAX;
            $rightPercent = $right['percent'] ?? \PHP_FLOAT_MAX;

            return $leftPercent <=> $rightPercent ?: $left['path'] <=> $right['path'];
        });

        return array_column($rows, 'markdown');
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function files(SimpleXMLElement $project): array
    {
        $files = [];
        foreach ($project->file as $file) {
            $files[] = $file;
        }
        foreach ($project->package as $package) {
            foreach ($package->file as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function metricRow(string $label, int $covered, int $total): string
    {
        return sprintf('| %s | %d | %d | %s |', $label, $covered, $total, $this->percent($covered, $total));
    }

    private function percent(int $covered, int $total): string
    {
        if ($total <= 0) {
            return 'n/a';
        }

        $value = round(($covered / $total) * 100, 2, \RoundingMode::TowardsZero);

        return sprintf('%01.2F%%', $value);
    }
}

$script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
$self = realpath(__FILE__);
if (\PHP_SAPI === 'cli' && \is_string($script) && \is_string($self) && $script === $self) {
    exit((new CoverageSummary())->run($argv));
}

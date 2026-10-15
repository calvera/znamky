<?php

declare(strict_types=1);

namespace App\Service\Stamp;

use Webmozart\Assert\Assert;

use function Safe\fclose;
use function Safe\fgetcsv;
use function Safe\fopen;

/**
 * Reads the semicolon-separated catalog CSVs (UTF-8, optional BOM, quoted header).
 */
final class StampCsvReader
{
    private const string BOM = "\xEF\xBB\xBF";

    /**
     * Yields non-empty data rows keyed by header column. Every header column is present
     * in each row, trimmed, and '' when the line is shorter than the header.
     *
     * @param list<string> $requiredColumns
     *
     * @return \Generator<int, array<string, string>>
     */
    public function read(string $path, array $requiredColumns): \Generator
    {
        $handle = fopen($path, 'rb');

        try {
            $header = $this->readLine($handle);
            if (null === $header) {
                return;
            }

            $indexes = $this->mapIndexes($header);
            foreach ($requiredColumns as $column) {
                Assert::keyExists($indexes, $column, \sprintf('CSV is missing required column "%s".', $column));
            }

            while (null !== ($line = $this->readLine($handle))) {
                if (!$this->isEmptyLine($line)) {
                    yield $this->toRow($line, $indexes);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return list<?string>|null
     */
    private function readLine($handle): ?array
    {
        $line = fgetcsv($handle, length: 0, separator: ';', enclosure: '"', escape: '\\');
        if (false === $line) {
            return null;
        }

        $values = array_values($line);
        Assert::allNullOrString($values);

        return $values;
    }

    /**
     * @param list<?string> $header
     *
     * @return array<string, int>
     */
    private function mapIndexes(array $header): array
    {
        $indexes = [];
        foreach ($header as $i => $column) {
            $column = (string) $column;
            if (0 === $i && str_starts_with($column, self::BOM)) {
                $column = substr($column, \strlen(self::BOM));
            }
            $column = trim($column, " \t\n\r\0\x0B\"");
            if ('' !== $column) {
                $indexes[$column] = $i;
            }
        }

        return $indexes;
    }

    /**
     * @param list<?string>      $line
     * @param array<string, int> $indexes
     *
     * @return array<string, string>
     */
    private function toRow(array $line, array $indexes): array
    {
        $row = [];
        foreach ($indexes as $column => $i) {
            $row[$column] = trim((string) ($line[$i] ?? ''));
        }

        return $row;
    }

    /**
     * @param list<?string> $line
     */
    private function isEmptyLine(array $line): bool
    {
        foreach ($line as $value) {
            if (null !== $value && '' !== trim($value)) {
                return false;
            }
        }

        return true;
    }
}

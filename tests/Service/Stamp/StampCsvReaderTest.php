<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Service\Stamp\StampCsvReader;
use PHPUnit\Framework\TestCase;

use function Safe\file_put_contents;
use function Safe\tempnam;
use function Safe\unlink;

final class StampCsvReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'stamp-csv-');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    public function testStripsBomAndKeysRowsByHeaderColumn(): void
    {
        file_put_contents($this->path, "\xEF\xBB\xBF\"Číslo\";\"Název\"\n\"1\";\" Praděd \"\n");

        self::assertSame([['Číslo' => '1', 'Název' => 'Praděd']], $this->read(['Číslo', 'Název']));
    }

    public function testSkipsBlankLinesAndPadsShortOnes(): void
    {
        file_put_contents($this->path, "\"Číslo\";\"Název\";\"Kraj\"\n\"\";\" \";\"\"\n\n\"2\";\"Sněžka\"\n");

        self::assertSame([['Číslo' => '2', 'Název' => 'Sněžka', 'Kraj' => '']], $this->read([]));
    }

    public function testOmitsColumnsMissingFromTheHeader(): void
    {
        file_put_contents($this->path, "\"Číslo\";\"\";\"Název\"\n\"3\";\"x\";\"Lysá hora\"\n");

        self::assertSame([['Číslo' => '3', 'Název' => 'Lysá hora']], $this->read([]));
    }

    public function testRejectsMissingRequiredColumn(): void
    {
        file_put_contents($this->path, "\"Číslo\"\n\"1\"\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV is missing required column "Název".');

        $this->read(['Číslo', 'Název']);
    }

    public function testEmptyFileYieldsNoRows(): void
    {
        self::assertSame([], $this->read(['Číslo']));
    }

    /**
     * @param list<string> $required
     *
     * @return list<array<string, string>>
     */
    private function read(array $required): array
    {
        return iterator_to_array((new StampCsvReader())->read($this->path, $required), false);
    }
}

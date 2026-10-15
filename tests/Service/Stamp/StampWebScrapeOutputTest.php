<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Service\Stamp\StampWebScrapeOutput;
use PHPUnit\Framework\TestCase;

use function Safe\file_get_contents;

final class StampWebScrapeOutputTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/znamky-output-'.bin2hex(random_bytes(4));
    }

    public function testCreatesStampsDirectory(): void
    {
        new StampWebScrapeOutput($this->dir.'/');

        self::assertDirectoryExists($this->dir.'/stamps');
    }

    public function testWritesPrettyUnescapedStampJsonWithTrailingNewline(): void
    {
        $output = new StampWebScrapeOutput($this->dir);
        self::assertFalse($output->hasStamp(7));

        $output->writeStamp(7, ['name' => 'Praděd', 'url' => 'https://x.test/a']);

        self::assertTrue($output->hasStamp(7));
        self::assertSame(
            "{\n    \"name\": \"Praděd\",\n    \"url\": \"https://x.test/a\"\n}\n",
            file_get_contents($this->dir.'/stamps/7/stamp.json'),
        );
    }

    public function testWritesStampFilesUnderTheStampDirectory(): void
    {
        $output = new StampWebScrapeOutput($this->dir);
        self::assertFalse($output->hasStampFile(7, 'images/current/a.jpg'));

        $output->writeStampFile(7, 'images/current/a.jpg', 'JPEG');

        self::assertTrue($output->hasStampFile(7, 'images/current/a.jpg'));
        self::assertSame('JPEG', file_get_contents($this->dir.'/stamps/7/images/current/a.jpg'));
    }

    public function testAppendsCompactUnescapedIndexLines(): void
    {
        $output = new StampWebScrapeOutput($this->dir);
        $output->appendIndex(['name' => 'Praděd', 'path' => 'stamps/1/stamp.json']);
        $output->appendIndex(['id' => 2]);

        self::assertSame(
            "{\"name\":\"Praděd\",\"path\":\"stamps/1/stamp.json\"}\n{\"id\":2}\n",
            file_get_contents($this->dir.'/index.jsonl'),
        );
    }

    public function testWriteMetaMergesWithEarlierWrites(): void
    {
        $output = new StampWebScrapeOutput($this->dir);
        $output->writeMeta(['startedAt' => 'a', 'stats' => 1]);
        $output->writeMeta(['stats' => 2]);

        self::assertSame(
            "{\n    \"startedAt\": \"a\",\n    \"stats\": 2\n}\n",
            file_get_contents($this->dir.'/scrape-meta.json'),
        );
    }
}

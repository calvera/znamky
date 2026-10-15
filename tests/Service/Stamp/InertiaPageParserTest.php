<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Service\Stamp\InertiaPageParser;
use PHPUnit\Framework\TestCase;

use function Safe\file_get_contents;

final class InertiaPageParserTest extends TestCase
{
    public function testParseItem(): void
    {
        $html = file_get_contents(__DIR__.'/../../fixtures/stamp_web/item_detail.html');
        $item = (new InertiaPageParser())->parseItem($html);

        self::assertSame(3535, $item['id']);
        self::assertSame('Praděd', $item['name']);
        $images = $item['images'] ?? null;
        self::assertIsArray($images);
        self::assertCount(2, $images);
    }

    public function testParsePageWithoutDataPageThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('data-page');
        (new InertiaPageParser())->parsePage('<html><body>nope</body></html>');
    }
}

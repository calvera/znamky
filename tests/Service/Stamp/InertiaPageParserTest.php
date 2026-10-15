<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Service\Stamp\InertiaPageParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Safe\file_get_contents;
use function Safe\json_encode;

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

    public function testParsePageKeepsStringComponentAndUrl(): void
    {
        $page = (new InertiaPageParser())->parsePage($this->html(['component' => 'Items', 'props' => ['a' => 1], 'url' => '/items']));

        self::assertSame(['a' => 1], $page['props']);
        self::assertSame('Items', $page['component'] ?? null);
        self::assertSame('/items', $page['url'] ?? null);
    }

    public function testParsePageDropsMissingOrNonStringComponentAndUrl(): void
    {
        $parser = new InertiaPageParser();

        self::assertSame(['props' => ['a' => 1]], $parser->parsePage($this->html(['props' => ['a' => 1]])));
        self::assertSame(['props' => ['a' => 1]], $parser->parsePage($this->html(['component' => 5, 'props' => ['a' => 1], 'url' => ['x']])));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function malformedPages(): iterable
    {
        yield 'not an object' => ['nope', 'Inertia page JSON must be an object.'];
        yield 'missing props' => [['component' => 'Items'], 'Inertia page JSON is missing props.'];
        yield 'props not an array' => [['props' => 'x'], 'Inertia props must be an array.'];
        yield 'props with int keys' => [['props' => ['x']], 'Expected string array keys.'];
    }

    #[DataProvider('malformedPages')]
    public function testParsePageRejectsMalformedJson(mixed $page, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new InertiaPageParser())->parsePage($this->html($page));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedItems(): iterable
    {
        yield 'missing item' => [[], 'Inertia page is missing props.item.'];
        yield 'item without id' => [['item' => ['name' => 'X']], 'Item is missing id.'];
    }

    /**
     * @param array<string, mixed> $props
     */
    #[DataProvider('malformedItems')]
    public function testParseItemRejectsMalformedItem(array $props, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new InertiaPageParser())->parseItem($this->html(['props' => $props]));
    }

    private function html(mixed $page): string
    {
        return '<div id="app" data-page="'.htmlspecialchars(json_encode($page), \ENT_QUOTES | \ENT_HTML5, 'UTF-8').'"></div>';
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Service\Stamp\PlaceLineParser;
use PHPUnit\Framework\TestCase;

final class PlaceLineParserTest extends TestCase
{
    private PlaceLineParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PlaceLineParser();
    }

    public function testParseLineWithHttpsUrl(): void
    {
        $result = $this->parser->parseLine('Restaurace Vysílač Praděd (https://www.hotelpradedvysilac.cz/)');

        self::assertSame([
            'name' => 'Restaurace Vysílač Praděd',
            'url' => 'https://www.hotelpradedvysilac.cz/',
        ], $result);
    }

    public function testParseLineWithNestedParensUsesLastAsUrl(): void
    {
        $result = $this->parser->parseLine('Hotel Luční Bouda (recepce hotelu) (lucnibouda.cz)');

        self::assertSame([
            'name' => 'Hotel Luční Bouda (recepce hotelu)',
            'url' => 'https://lucnibouda.cz',
        ], $result);
    }

    public function testParseLineWithWwwNormalizesToHttps(): void
    {
        $result = $this->parser->parseLine('Chata Jiřího na Šeráku, Ramzová (www.jeseniky.net/chata-serak)');

        self::assertSame([
            'name' => 'Chata Jiřího na Šeráku, Ramzová',
            'url' => 'https://www.jeseniky.net/chata-serak',
        ], $result);
    }

    public function testParseLineWithoutUrl(): void
    {
        $result = $this->parser->parseLine('Pouze pro účastníky fotografické soutěže');

        self::assertSame([
            'name' => 'Pouze pro účastníky fotografické soutěže',
            'url' => null,
        ], $result);
    }

    public function testParseCellMultiline(): void
    {
        $cell = "Chata Ovčárna - Vojenská Zotavovna (http://www.ovcarna.cz)\nChata Sabinka (http://www.chatasabinka.cz/)\nHotel Figura (https://www.figura.cz/)";

        $places = $this->parser->parseCell($cell);

        self::assertCount(3, $places);
        self::assertSame('Chata Ovčárna - Vojenská Zotavovna', $places[0]['name']);
        self::assertSame('http://www.ovcarna.cz', $places[0]['url']);
        self::assertSame('Hotel Figura', $places[2]['name']);
    }

    public function testParseCellSkipsBlankLinesBetweenPlaces(): void
    {
        $cell = "Chata Ovčárna (http://www.ovcarna.cz)\n\nHotel Figura (https://www.figura.cz/)";

        $places = $this->parser->parseCell($cell);

        self::assertSame([
            ['name' => 'Chata Ovčárna', 'url' => 'http://www.ovcarna.cz'],
            ['name' => 'Hotel Figura', 'url' => 'https://www.figura.cz/'],
        ], $places);
    }

    public function testParseTags(): void
    {
        $tags = $this->parser->parseTags('Rozhledny a vyhlídky, CHKO a rezervace, Pohoří, Jeseníky');

        self::assertSame([
            'Rozhledny a vyhlídky',
            'CHKO a rezervace',
            'Pohoří',
            'Jeseníky',
        ], $tags);
    }

    public function testParseEmptyCell(): void
    {
        self::assertSame([], $this->parser->parseCell(null));
        self::assertSame([], $this->parser->parseCell(''));
        self::assertSame([], $this->parser->parseTags(null));
    }

    public function testParentheticalNoteIsNotTreatedAsUrl(): void
    {
        self::assertSame([
            'name' => 'Hotel Luční Bouda (recepce hotelu)',
            'url' => null,
        ], $this->parser->parseLine('Hotel Luční Bouda (recepce hotelu)'));
    }

    public function testUrlOnlyLineIsIgnored(): void
    {
        self::assertNull($this->parser->parseLine('(https://example.com)'));
        self::assertSame([], $this->parser->parseCell("(https://example.com)\n\n"));
    }

    public function testParseTagsDropsDuplicatesAndBlanks(): void
    {
        self::assertSame(
            ['Hory', 'Jeseníky'],
            $this->parser->parseTags('Hory, , Jeseníky, Hory'),
        );
    }
}

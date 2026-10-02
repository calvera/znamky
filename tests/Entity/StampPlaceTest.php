<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\StampPlace;
use PHPUnit\Framework\TestCase;

final class StampPlaceTest extends TestCase
{
    public function testCatalogKeyTreatsMissingUrlAsEmptyAndChangesWithUrl(): void
    {
        self::assertSame(
            StampPlace::buildCatalogKey('Informační centrum', null),
            StampPlace::buildCatalogKey('Informační centrum', ''),
        );
        self::assertNotSame(
            StampPlace::buildCatalogKey('Informační centrum', null),
            StampPlace::buildCatalogKey('Informační centrum', 'https://example.com'),
        );

        $place = (new StampPlace())
            ->setName('Informační centrum')
            ->setUrl(null);
        self::assertSame(StampPlace::buildCatalogKey('Informační centrum', null), $place->getCatalogKey());

        $place->setUrl('https://example.com');
        self::assertSame(
            StampPlace::buildCatalogKey('Informační centrum', 'https://example.com'),
            $place->getCatalogKey(),
        );
    }
}

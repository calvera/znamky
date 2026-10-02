<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Entity\Stamp;
use App\Entity\StampPlace;
use App\Entity\StampTag;
use App\Enum\StampCountry;
use App\Enum\StampType;
use App\Repository\StampPlaceRepository;
use App\Repository\StampRepository;
use App\Service\Stamp\StampImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StampImportServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StampImportService $importService;
    private StampRepository $stampRepository;
    private StampPlaceRepository $placeRepository;
    private string $fixtureDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $this->em = $em;
        /** @var StampImportService $importService */
        $importService = $container->get(StampImportService::class);
        $this->importService = $importService;
        /** @var StampRepository $stampRepository */
        $stampRepository = $container->get(StampRepository::class);
        $this->stampRepository = $stampRepository;
        /** @var StampPlaceRepository $placeRepository */
        $placeRepository = $container->get(StampPlaceRepository::class);
        $this->placeRepository = $placeRepository;

        $this->purgeCatalog();
        $this->fixtureDir = sys_get_temp_dir().'/znamky_stamp_import_'.uniqid('', true);
        mkdir($this->fixtureDir);
        $this->writeFixtures();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fixtureDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->fixtureDir);
        parent::tearDown();
    }

    public function testImportCreatesStampsTagsAndSharedPlaces(): void
    {
        $result = $this->importService->import($this->fixtureDir);

        self::assertSame(3, $result['stamps']);
        self::assertSame(1, $result['files']);
        self::assertGreaterThanOrEqual(3, $result['tags']);
        self::assertSame(1, $result['places']);

        $stamp1 = $this->stampRepository->findOneByIdentity(StampCountry::Cz, StampType::Regular, 1);
        $stamp2 = $this->stampRepository->findOneByIdentity(StampCountry::Cz, StampType::Regular, 2);
        self::assertInstanceOf(Stamp::class, $stamp1);
        self::assertInstanceOf(Stamp::class, $stamp2);
        self::assertSame('Praděd', $stamp1->getName());
        self::assertCount(3, $stamp1->getTags());
        self::assertCount(1, $stamp1->getPlaces());
        self::assertCount(1, $stamp2->getPlaces());

        $place1 = $stamp1->getPlaces()->first();
        $place2 = $stamp2->getPlaces()->first();
        self::assertInstanceOf(StampPlace::class, $place1);
        self::assertInstanceOf(StampPlace::class, $place2);
        self::assertSame($place1->getId(), $place2->getId());
        self::assertSame('Shared Info Center', $place1->getName());
        self::assertSame('https://example.com', $place1->getUrl());

        self::assertSame(1, $this->placeRepository->count([]));
    }

    public function testReimportPreservesCoordinates(): void
    {
        $this->importService->import($this->fixtureDir);

        $stamp = $this->stampRepository->findOneByIdentity(StampCountry::Cz, StampType::Regular, 1);
        self::assertNotNull($stamp);
        $stamp->setLatitude(50.0)->setLongitude(17.0);
        $place = $stamp->getPlaces()->first();
        self::assertInstanceOf(StampPlace::class, $place);
        $place->setLatitude(49.5)->setLongitude(16.5);
        $this->em->flush();
        $this->em->clear();

        $this->importService->import($this->fixtureDir);

        $stamp = $this->stampRepository->findOneByIdentity(StampCountry::Cz, StampType::Regular, 1);
        self::assertNotNull($stamp);
        self::assertSame(50.0, $stamp->getLatitude());
        self::assertSame(17.0, $stamp->getLongitude());
        $place = $stamp->getPlaces()->first();
        self::assertInstanceOf(StampPlace::class, $place);
        self::assertSame(49.5, $place->getLatitude());
        self::assertSame(16.5, $place->getLongitude());
    }

    public function testImportRejectsMissingDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->importService->import($this->fixtureDir.'/missing');
    }

    public function testImportIgnoresFilesOtherThanKnownCatalogs(): void
    {
        unlink($this->fixtureDir.'/cs-stamps.csv');
        file_put_contents($this->fixtureDir.'/notes.csv', "Číslo;Název\n1;X\n");

        self::assertSame(
            ['stamps' => 0, 'tags' => 0, 'places' => 0, 'files' => 0],
            $this->importService->import($this->fixtureDir),
        );
    }

    public function testImportRejectsCsvWithoutRequiredColumns(): void
    {
        $this->writeCsv('cs-stamps.csv', "\"A\";\"B\"\n\"1\";\"X\"\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Číslo');

        $this->importService->import($this->fixtureDir);
    }

    public function testImportSkipsBlankAndNumberlessRows(): void
    {
        $this->writeCsv('cs-stamps.csv', <<<'CSV'
"Číslo";"Název";"Země";"Typ";"Kategorie";"Okres";"Kraj";"Prodejní místa (název a web)"
"";"";"";"";"";"";"";""
"0";"Ignored";"Česká Republika";"Turistická známka";;;;
"";"No number";"Česká Republika";"Turistická známka";;;;
"4";"Kept";"Česká Republika";"Turistická známka";"Hory";;;
CSV);

        $this->importService->import($this->fixtureDir);

        self::assertSame(1, $this->stampRepository->count([]));
        $stamp = $this->stampRepository->findOneByIdentity(StampCountry::Cz, StampType::Regular, 4);
        self::assertInstanceOf(Stamp::class, $stamp);
        self::assertSame('Kept', $stamp->getName());
        self::assertNull($this->stampRepository->findOneByIdentity(StampCountry::Cz, StampType::Regular, 0));
    }

    public function testCountryAndTypeComeFromColumnsOrFileDefaults(): void
    {
        $this->writeCsv('cs-stamps.csv', <<<'CSV'
"Číslo";"Název";"Země";"Typ"
"10";"Výroční SK";"Slovenská Republika";"Výroční turistická známka"
CSV);
        $this->writeCsv('sk-stamps.csv', <<<'CSV'
"Číslo";"Název"
"3";"Zbojnícka chata"
CSV);
        $this->writeCsv('cs-annual.csv', <<<'CSV'
"Číslo";"Název"
"8";"Roční známka"
CSV);

        $result = $this->importService->import($this->fixtureDir);

        self::assertSame(3, $result['stamps']);
        self::assertSame(3, $result['files']);
        self::assertInstanceOf(
            Stamp::class,
            $this->stampRepository->findOneByIdentity(StampCountry::Sk, StampType::Annual, 10),
        );
        self::assertInstanceOf(
            Stamp::class,
            $this->stampRepository->findOneByIdentity(StampCountry::Sk, StampType::Regular, 3),
        );
        self::assertInstanceOf(
            Stamp::class,
            $this->stampRepository->findOneByIdentity(StampCountry::Cz, StampType::Annual, 8),
        );
    }

    public function testSamePlaceNameWithDifferentUrlsStaysDistinct(): void
    {
        $this->writeCsv('cs-stamps.csv', <<<'CSV'
"Číslo";"Název";"Země";"Typ";"Kategorie";"Okres";"Kraj";"Prodejní místa (název a web)"
"1";"První";"Česká Republika";"Turistická známka";"Jeseníky";"";"";"Infocentrum (https://a.example)
Infocentrum (https://b.example)"
"2";"Druhá";"Česká Republika";"Turistická známka";"Jeseníky";"";"";"Infocentrum (https://a.example)"
CSV);

        $result = $this->importService->import($this->fixtureDir);

        self::assertSame(2, $result['stamps']);
        self::assertSame(2, $result['places']);
        self::assertSame(1, $result['tags']);

        $first = $this->stampRepository->findOneByIdentity(StampCountry::Cz, StampType::Regular, 1);
        $second = $this->stampRepository->findOneByIdentity(StampCountry::Cz, StampType::Regular, 2);
        self::assertInstanceOf(Stamp::class, $first);
        self::assertInstanceOf(Stamp::class, $second);
        self::assertCount(2, $first->getPlaces());
        self::assertCount(1, $second->getPlaces());

        $shared = $second->getPlaces()->first();
        self::assertInstanceOf(StampPlace::class, $shared);
        self::assertSame('https://a.example', $shared->getUrl());
        self::assertTrue($first->getPlaces()->contains($shared));

        $urls = [];
        foreach ($first->getPlaces() as $place) {
            $urls[] = $place->getUrl();
        }
        sort($urls);
        self::assertSame(['https://a.example', 'https://b.example'], $urls);

        $tag = $first->getTags()->first();
        self::assertInstanceOf(StampTag::class, $tag);
        self::assertSame('Jeseníky', $tag->getName());
        self::assertSame('jeseniky', $tag->getSlug());
    }

    public function testImportRejectsUnknownStampType(): void
    {
        $this->writeCsv('cs-stamps.csv', <<<'CSV'
"Číslo";"Název";"Země";"Typ"
"1";"X";"Česká Republika";"Neznámý"
CSV);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Neznámý');

        $this->importService->import($this->fixtureDir);
    }

    public function testImportRejectsUnknownCountry(): void
    {
        $this->writeCsv('cs-stamps.csv', <<<'CSV'
"Číslo";"Název";"Země";"Typ"
"1";"X";"Německo";"Turistická známka"
CSV);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Německo');

        $this->importService->import($this->fixtureDir);
    }

    public function testPurgeDropsExistingCatalogAndCoordinates(): void
    {
        $this->importService->import($this->fixtureDir);

        $stamp = $this->stampRepository->findOneByIdentity(StampCountry::Cz, StampType::Regular, 1);
        self::assertNotNull($stamp);
        $stamp->setLatitude(50.0)->setLongitude(17.0);
        $extra = (new Stamp())
            ->setCountry(StampCountry::Sk)
            ->setType(StampType::Annual)
            ->setNumber(99)
            ->setName('Doomed');
        $this->em->persist($extra);
        $this->em->flush();
        $this->em->clear();

        $this->importService->import($this->fixtureDir, true);

        self::assertNull($this->stampRepository->findOneByIdentity(StampCountry::Sk, StampType::Annual, 99));
        $stamp = $this->stampRepository->findOneByIdentity(StampCountry::Cz, StampType::Regular, 1);
        self::assertInstanceOf(Stamp::class, $stamp);
        self::assertNull($stamp->getLatitude());
        self::assertNull($stamp->getLongitude());
    }

    public function testReimportReplacesTagsAndPlaces(): void
    {
        $this->importService->import($this->fixtureDir);

        $this->writeCsv('cs-stamps.csv', <<<'CSV'
"Číslo";"Název";"Země";"Typ";"Kategorie";"Okres";"Kraj";"Prodejní místa (název a web)"
"1";"Praděd nový";"Česká Republika";"Turistická známka";"Nové";"Bruntál";"Moravskoslezský kraj";"Jiná budova"
"2";"Ovčárna";"Česká Republika";"Turistická známka";"Pohoří, Jeseníky";"Bruntál";"Moravskoslezský kraj";"Shared Info Center (https://example.com)"
"3";"Karlova Studánka";"Česká Republika";"Turistická známka";"Lázně, Jeseníky";"Bruntál";"Moravskoslezský kraj";"Shared Info Center (https://example.com)"
CSV);

        $this->importService->import($this->fixtureDir);

        $stamp = $this->stampRepository->findOneByIdentity(StampCountry::Cz, StampType::Regular, 1);
        self::assertInstanceOf(Stamp::class, $stamp);
        self::assertSame('Praděd nový', $stamp->getName());
        self::assertCount(1, $stamp->getTags());
        $tag = $stamp->getTags()->first();
        self::assertInstanceOf(StampTag::class, $tag);
        self::assertSame('Nové', $tag->getName());
        self::assertCount(1, $stamp->getPlaces());
        $place = $stamp->getPlaces()->first();
        self::assertInstanceOf(StampPlace::class, $place);
        self::assertSame('Jiná budova', $place->getName());
        self::assertNull($place->getUrl());
    }

    private function writeCsv(string $filename, string $body): void
    {
        file_put_contents($this->fixtureDir.'/'.$filename, $body);
    }

    private function writeFixtures(): void
    {
        $csv = <<<'CSV'
"Číslo";"Název";"Země";"Typ";"Kategorie";"Okres";"Kraj";"Prodejní místa (název a web)"
"1";"Praděd";"Česká Republika";"Turistická známka";"Rozhledny a vyhlídky, Pohoří, Jeseníky";"Bruntál";"Moravskoslezský kraj";"Shared Info Center (https://example.com)"
"2";"Ovčárna";"Česká Republika";"Turistická známka";"Pohoří, Jeseníky";"Bruntál";"Moravskoslezský kraj";"Shared Info Center (https://example.com)"
"3";"Karlova Studánka";"Česká Republika";"Turistická známka";"Lázně, Jeseníky";"Bruntál";"Moravskoslezský kraj";"Shared Info Center (https://example.com)"
CSV;
        file_put_contents($this->fixtureDir.'/cs-stamps.csv', "\xEF\xBB\xBF".$csv);
    }

    private function purgeCatalog(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('TRUNCATE TABLE stamp_stamp_tag, stamp_stamp_place, stamps, stamp_tags, stamp_places RESTART IDENTITY CASCADE');
        $this->em->clear();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Entity\Stamp;
use App\Entity\StampPlace;
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

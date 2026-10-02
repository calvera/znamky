<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Entity\Stamp;
use App\Entity\StampPlace;
use App\Enum\StampCountry;
use App\Enum\StampType;
use App\Repository\StampPlaceRepository;
use App\Repository\StampRepository;
use App\Service\Stamp\GoogleGeocoder;
use App\Service\Stamp\StampGeocodeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StampGeocodePersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $this->em = $em;
        $this->purgeCatalog();
    }

    public function testGeocodeWritesResultsSkipsCompleteRowsAndCountsFailures(): void
    {
        $ready = (new Stamp())
            ->setCountry(StampCountry::Cz)
            ->setType(StampType::Regular)
            ->setNumber(1)
            ->setName('Praděd')
            ->setRegion('Moravskoslezský kraj');
        $done = (new Stamp())
            ->setCountry(StampCountry::Cz)
            ->setType(StampType::Regular)
            ->setNumber(2)
            ->setName('Hotový')
            ->setLatitude(10.0)
            ->setLongitude(20.0);
        $missing = (new Stamp())
            ->setCountry(StampCountry::Sk)
            ->setType(StampType::Regular)
            ->setNumber(3)
            ->setName('Missing Peak');
        $place = (new StampPlace())->setName('Informační centrum');
        $placed = (new StampPlace())
            ->setName('Už označené')
            ->setLatitude(1.0)
            ->setLongitude(2.0);
        $blank = (new StampPlace())->setName('   ');

        $this->em->persist($ready);
        $this->em->persist($done);
        $this->em->persist($missing);
        $this->em->persist($place);
        $this->em->persist($placed);
        $this->em->persist($blank);
        $this->em->flush();
        $readyId = $ready->getId();
        $missingId = $missing->getId();
        $placeId = $place->getId();
        $this->em->clear();

        $calls = [];
        $this->replaceGeocoder($calls);

        /** @var StampGeocodeService $service */
        $service = static::getContainer()->get(StampGeocodeService::class);
        $events = [];
        $onProgress = function (string $kind, ?int $id, bool $ok) use (&$events): void {
            $events[] = [$kind, $id, $ok];
        };

        self::assertSame(
            ['geocoded' => 1, 'skipped' => 0, 'failed' => 1],
            $service->geocodeStamps(false, $onProgress),
        );
        self::assertSame(
            ['geocoded' => 1, 'skipped' => 1, 'failed' => 0],
            $service->geocodePlaces(false, $onProgress),
        );

        self::assertSame([
            'Praděd, Moravskoslezský kraj, Czech Republic',
            'Missing Peak, Slovakia',
            'Informační centrum',
        ], $calls);
        self::assertSame([
            ['stamp', $readyId, true],
            ['stamp', $missingId, false],
            ['place', $placeId, true],
        ], $events);

        $ready = $this->stamps()->findOneByIdentity(StampCountry::Cz, StampType::Regular, 1);
        $done = $this->stamps()->findOneByIdentity(StampCountry::Cz, StampType::Regular, 2);
        $missing = $this->stamps()->findOneByIdentity(StampCountry::Sk, StampType::Regular, 3);
        self::assertInstanceOf(Stamp::class, $ready);
        self::assertInstanceOf(Stamp::class, $done);
        self::assertInstanceOf(Stamp::class, $missing);
        self::assertSame(50.5, $ready->getLatitude());
        self::assertSame(17.5, $ready->getLongitude());
        self::assertSame(10.0, $done->getLatitude());
        self::assertSame(20.0, $done->getLongitude());
        self::assertNull($missing->getLatitude());
        self::assertNull($missing->getLongitude());

        $place = $this->places()->findOneByNameAndUrl('Informační centrum', null);
        $placed = $this->places()->findOneByNameAndUrl('Už označené', null);
        $blank = $this->places()->findOneByNameAndUrl('   ', null);
        self::assertInstanceOf(StampPlace::class, $place);
        self::assertInstanceOf(StampPlace::class, $placed);
        self::assertInstanceOf(StampPlace::class, $blank);
        self::assertSame(50.5, $place->getLatitude());
        self::assertSame(17.5, $place->getLongitude());
        self::assertSame(1.0, $placed->getLatitude());
        self::assertSame(2.0, $placed->getLongitude());
        self::assertNull($blank->getLatitude());
    }

    public function testForceGeocodeRewritesExistingCoordinates(): void
    {
        $stamp = (new Stamp())
            ->setCountry(StampCountry::Cz)
            ->setType(StampType::Regular)
            ->setNumber(1)
            ->setName('Praděd')
            ->setLatitude(10.0)
            ->setLongitude(20.0);
        $this->em->persist($stamp);
        $this->em->flush();
        $this->em->clear();

        $calls = [];
        $this->replaceGeocoder($calls);

        /** @var StampGeocodeService $service */
        $service = static::getContainer()->get(StampGeocodeService::class);

        self::assertSame(
            ['geocoded' => 0, 'skipped' => 0, 'failed' => 0],
            $service->geocodeStamps(false),
        );
        self::assertSame([], $calls);

        self::assertSame(
            ['geocoded' => 1, 'skipped' => 0, 'failed' => 0],
            $service->geocodeStamps(true),
        );

        $stamp = $this->stamps()->findOneByIdentity(StampCountry::Cz, StampType::Regular, 1);
        self::assertInstanceOf(Stamp::class, $stamp);
        self::assertSame(50.5, $stamp->getLatitude());
        self::assertSame(17.5, $stamp->getLongitude());
    }

    /**
     * @param list<string> $calls
     */
    private function replaceGeocoder(array &$calls): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $address = (string) $options['query']['address'];
            $calls[] = $address;

            if (str_contains($address, 'Missing Peak')) {
                return new MockResponse(json_encode([
                    'status' => 'ZERO_RESULTS',
                    'results' => [],
                ], \JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode([
                'status' => 'OK',
                'results' => [
                    ['geometry' => ['location' => ['lat' => 50.5, 'lng' => 17.5]]],
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        static::getContainer()->set(GoogleGeocoder::class, new GoogleGeocoder($client, 'test-key'));
    }

    private function stamps(): StampRepository
    {
        /** @var StampRepository $repository */
        $repository = static::getContainer()->get(StampRepository::class);

        return $repository;
    }

    private function places(): StampPlaceRepository
    {
        /** @var StampPlaceRepository $repository */
        $repository = static::getContainer()->get(StampPlaceRepository::class);

        return $repository;
    }

    private function purgeCatalog(): void
    {
        $this->em->getConnection()->executeStatement(
            'TRUNCATE TABLE stamp_stamp_tag, stamp_stamp_place, stamps, stamp_tags, stamp_places RESTART IDENTITY CASCADE',
        );
        $this->em->clear();
    }
}

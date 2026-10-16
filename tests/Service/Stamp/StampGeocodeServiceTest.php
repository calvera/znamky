<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Entity\Stamp;
use App\Entity\StampPlace;
use App\Enum\StampCountry;
use App\Enum\StampType;
use App\Repository\StampPlaceRepository;
use App\Repository\StampRepository;
use App\Service\Stamp\GeocoderInterface;
use App\Service\Stamp\GoogleGeocoder;
use App\Service\Stamp\StampGeocodeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class StampGeocodeServiceTest extends TestCase
{
    public function testBuildStampQueryIncludesNameRegionCountry(): void
    {
        $service = $this->createService();

        $stamp = (new Stamp())
            ->setName('Praděd')
            ->setRegion('Moravskoslezský kraj')
            ->setCountry(StampCountry::Cz)
            ->setType(StampType::Regular)
            ->setNumber(1);

        self::assertSame(
            'Praděd, Moravskoslezský kraj, Czech Republic',
            $service->buildStampQuery($stamp),
        );
    }

    public function testBuildStampQueryOmitsEmptyRegion(): void
    {
        $service = $this->createService();

        $stamp = (new Stamp())
            ->setName('Zbojnícka chata')
            ->setCountry(StampCountry::Sk)
            ->setType(StampType::Regular)
            ->setNumber(1);

        self::assertSame(
            'Zbojnícka chata, Slovakia',
            $service->buildStampQuery($stamp),
        );
    }

    public function testGeocodeWaitsDelayAfterEveryApiCall(): void
    {
        $places = [
            (new StampPlace())->setName('Found'),
            (new StampPlace())->setName('Missing'),
            (new StampPlace())->setName('   '),
        ];
        $placeRepository = $this->createStub(StampPlaceRepository::class);
        $placeRepository->method('iterateNeedingGeocode')->willReturn(new \ArrayIterator($places));
        $geocoder = $this->createStub(GeocoderInterface::class);
        $geocoder->method('geocode')->willReturnCallback(
            static fn (string $address): ?array => str_starts_with($address, 'Found') ? ['lat' => 1.0, 'lng' => 2.0] : null,
        );
        $clock = new MockClock('2026-01-01 00:00:00');

        $service = new StampGeocodeService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(StampRepository::class),
            $placeRepository,
            $geocoder,
            $clock,
        );

        self::assertSame(
            ['geocoded' => 1, 'skipped' => 1, 'failed' => 1],
            $service->geocodePlaces(delayMs: 250),
        );
        self::assertSame('2026-01-01 00:00:00.500000', $clock->now()->format('Y-m-d H:i:s.u'));
    }

    private function createService(): StampGeocodeService
    {
        return new StampGeocodeService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(StampRepository::class),
            $this->createStub(StampPlaceRepository::class),
            new GoogleGeocoder(new MockHttpClient(), 'test-key'),
            new MockClock(),
        );
    }
}

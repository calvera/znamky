<?php

declare(strict_types=1);

namespace App\Tests\Service\Stamp;

use App\Entity\Stamp;
use App\Enum\StampCountry;
use App\Enum\StampType;
use App\Repository\StampPlaceRepository;
use App\Repository\StampRepository;
use App\Service\Stamp\GoogleGeocoder;
use App\Service\Stamp\StampGeocodeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
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

    private function createService(): StampGeocodeService
    {
        return new StampGeocodeService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(StampRepository::class),
            $this->createStub(StampPlaceRepository::class),
            new GoogleGeocoder(new MockHttpClient(), 'test-key'),
        );
    }
}

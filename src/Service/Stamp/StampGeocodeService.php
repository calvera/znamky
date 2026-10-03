<?php

declare(strict_types=1);

namespace App\Service\Stamp;

use App\Entity\Stamp;
use App\Repository\StampPlaceRepository;
use App\Repository\StampRepository;
use Doctrine\ORM\EntityManagerInterface;

final class StampGeocodeService
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StampRepository $stampRepository,
        private readonly StampPlaceRepository $stampPlaceRepository,
        private readonly GeocoderInterface $geocoder,
    ) {
    }

    /**
     * @return array{geocoded: int, skipped: int, failed: int}
     */
    public function geocodeStamps(bool $force = false, ?callable $onProgress = null): array
    {
        $stats = ['geocoded' => 0, 'skipped' => 0, 'failed' => 0];
        $processed = 0;

        foreach ($this->stampRepository->iterateNeedingGeocode($force) as $stamp) {
            $query = $this->buildStampQuery($stamp);
            if ('' === $query) {
                ++$stats['skipped'];
                continue;
            }

            $coords = $this->geocoder->geocode($query);
            if (null === $coords) {
                ++$stats['failed'];
                $onProgress && $onProgress('stamp', $stamp->getId(), false);
                continue;
            }

            $stamp
                ->setLatitude($coords['lat'])
                ->setLongitude($coords['lng']);
            ++$stats['geocoded'];
            $onProgress && $onProgress('stamp', $stamp->getId(), true);

            ++$processed;
            if (0 === $processed % self::BATCH_SIZE) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return $stats;
    }

    /**
     * @return array{geocoded: int, skipped: int, failed: int}
     */
    public function geocodePlaces(bool $force = false, ?callable $onProgress = null): array
    {
        $stats = ['geocoded' => 0, 'skipped' => 0, 'failed' => 0];
        $processed = 0;

        foreach ($this->stampPlaceRepository->iterateNeedingGeocode($force) as $place) {
            $query = trim($place->getName());
            if ('' === $query) {
                ++$stats['skipped'];
                continue;
            }

            $coords = $this->geocoder->geocode($query);
            if (null === $coords) {
                ++$stats['failed'];
                $onProgress && $onProgress('place', $place->getId(), false);
                continue;
            }

            $place
                ->setLatitude($coords['lat'])
                ->setLongitude($coords['lng']);
            ++$stats['geocoded'];
            $onProgress && $onProgress('place', $place->getId(), true);

            ++$processed;
            if (0 === $processed % self::BATCH_SIZE) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return $stats;
    }

    public function buildStampQuery(Stamp $stamp): string
    {
        $parts = array_filter([
            $stamp->getName(),
            $stamp->getRegion(),
            $stamp->getCountry()->label(),
        ], static fn (?string $part): bool => null !== $part && '' !== trim($part));

        return implode(', ', $parts);
    }
}

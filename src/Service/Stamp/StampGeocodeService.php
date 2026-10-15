<?php

declare(strict_types=1);

namespace App\Service\Stamp;

use App\Entity\Geocodable;
use App\Entity\Stamp;
use App\Entity\StampPlace;
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
     * @param (callable(string, ?int, bool): void)|null $onProgress
     *
     * @return array{geocoded: int, skipped: int, failed: int}
     */
    public function geocodeStamps(bool $force = false, ?callable $onProgress = null): array
    {
        return $this->geocodeAll(
            $this->stampRepository->iterateNeedingGeocode($force),
            'stamp',
            $this->buildStampQuery(...),
            $onProgress,
        );
    }

    /**
     * @param (callable(string, ?int, bool): void)|null $onProgress
     *
     * @return array{geocoded: int, skipped: int, failed: int}
     */
    public function geocodePlaces(bool $force = false, ?callable $onProgress = null): array
    {
        return $this->geocodeAll(
            $this->stampPlaceRepository->iterateNeedingGeocode($force),
            'place',
            static fn (StampPlace $place): string => trim($place->getName()),
            $onProgress,
        );
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

    /**
     * Geocodes each row, flushing and clearing the entity manager every BATCH_SIZE successes.
     *
     * @template T of Geocodable
     *
     * @param iterable<T>                               $rows
     * @param \Closure(T): string                       $queryOf
     * @param (callable(string, ?int, bool): void)|null $onProgress
     *
     * @return array{geocoded: int, skipped: int, failed: int}
     */
    private function geocodeAll(iterable $rows, string $kind, \Closure $queryOf, ?callable $onProgress): array
    {
        $stats = ['geocoded' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $query = $queryOf($row);
            if ('' === $query) {
                ++$stats['skipped'];
                continue;
            }

            $coords = $this->geocoder->geocode($query);
            if (null === $coords) {
                ++$stats['failed'];
                if (null !== $onProgress) {
                    $onProgress($kind, $row->getId(), false);
                }
                continue;
            }

            $row
                ->setLatitude($coords['lat'])
                ->setLongitude($coords['lng']);
            ++$stats['geocoded'];
            if (null !== $onProgress) {
                $onProgress($kind, $row->getId(), true);
            }

            if (0 === $stats['geocoded'] % self::BATCH_SIZE) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return $stats;
    }
}

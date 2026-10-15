<?php

declare(strict_types=1);

namespace App\Service\Stamp;

use App\Entity\Stamp;
use App\Entity\StampPlace;
use App\Entity\StampTag;
use App\Enum\StampCountry;
use App\Enum\StampType;
use App\Repository\StampPlaceRepository;
use App\Repository\StampRepository;
use App\Repository\StampTagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Webmozart\Assert\Assert;

final class StampImportService
{
    private const BATCH_SIZE = 100;

    /** @var array<string, StampTag> */
    private array $tagCache = [];

    /** @var array<string, StampPlace> */
    private array $placeCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StampRepository $stampRepository,
        private readonly StampTagRepository $stampTagRepository,
        private readonly StampPlaceRepository $stampPlaceRepository,
        private readonly PlaceLineParser $placeLineParser,
        private readonly SluggerInterface $slugger,
        private readonly StampCsvReader $csvReader,
    ) {
    }

    /**
     * @return array{stamps: int, tags: int, places: int, files: int}
     */
    public function import(string $directory, bool $purge = false): array
    {
        $directory = Assert::directory(rtrim($directory, '/\\'), 'Import directory does not exist: "%s".');

        if ($purge) {
            $this->purgeCatalog();
        }

        $this->resetCaches();

        $files = [
            'cs-stamps.csv' => [StampCountry::Cz, StampType::Regular],
            'sk-stamps.csv' => [StampCountry::Sk, StampType::Regular],
            'cs-annual.csv' => [StampCountry::Cz, StampType::Annual],
        ];

        $stampCount = 0;
        $filesImported = 0;

        foreach ($files as $filename => [$defaultCountry, $defaultType]) {
            $path = $directory.\DIRECTORY_SEPARATOR.$filename;
            if (!is_file($path)) {
                continue;
            }

            $stampCount += $this->importFile($path, $defaultCountry, $defaultType);
            ++$filesImported;
        }

        $this->flushAndClear();

        return [
            'stamps' => $stampCount,
            'tags' => (int) $this->stampTagRepository->count([]),
            'places' => (int) $this->stampPlaceRepository->count([]),
            'files' => $filesImported,
        ];
    }

    /**
     * Wipe the stamp catalog with TRUNCATE CASCADE (fast, resets identities).
     * Prefer this over ORM deletes when clearing the full catalog for re-import.
     */
    private function purgeCatalog(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('TRUNCATE TABLE stamp_stamp_tag, stamp_stamp_place, stamps, stamp_tags, stamp_places RESTART IDENTITY CASCADE');
        $this->entityManager->clear();
        $this->resetCaches();
    }

    /**
     * Writes pending changes and detaches all entities, dropping cached tags and places with them.
     */
    private function flushAndClear(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->resetCaches();
    }

    private function resetCaches(): void
    {
        $this->tagCache = [];
        $this->placeCache = [];
    }

    private function importFile(string $path, StampCountry $defaultCountry, StampType $defaultType): int
    {
        $count = 0;
        foreach ($this->csvReader->read($path, ['Číslo', 'Název']) as $row) {
            $this->importRow($row, $defaultCountry, $defaultType);
            ++$count;

            if (0 === $count % self::BATCH_SIZE) {
                $this->flushAndClear();
            }
        }

        return $count;
    }

    /**
     * @param array<string, string> $row
     */
    private function importRow(array $row, StampCountry $defaultCountry, StampType $defaultType): void
    {
        $number = (int) $row['Číslo'];
        $name = $row['Název'];
        if (0 === $number || '' === $name) {
            return;
        }

        $country = isset($row['Země']) ? StampCountry::fromCsv($row['Země']) : $defaultCountry;
        $type = isset($row['Typ']) ? StampType::fromCsv($row['Typ']) : $defaultType;

        $district = $this->nullable($row['Okres'] ?? '');
        $region = $this->nullable($row['Kraj'] ?? '');
        $kategorie = $this->nullable($row['Kategorie'] ?? '');
        $placesCell = $this->nullable($row['Prodejní místa (název a web)'] ?? '');

        $stamp = $this->stampRepository->findOneByIdentity($country, $type, $number);
        if (null === $stamp) {
            $stamp = (new Stamp())
                ->setCountry($country)
                ->setType($type)
                ->setNumber($number);
            $this->entityManager->persist($stamp);
        }

        $stamp
            ->setName($name)
            ->setDistrict($district)
            ->setRegion($region);

        $stamp->clearTags();
        foreach ($this->placeLineParser->parseTags($kategorie) as $tagName) {
            $stamp->addTag($this->getOrCreateTag($tagName));
        }

        $stamp->clearPlaces();
        foreach ($this->placeLineParser->parseCell($placesCell) as $placeData) {
            $stamp->addPlace($this->getOrCreatePlace($placeData['name'], $placeData['url']));
        }
    }

    private function getOrCreateTag(string $name): StampTag
    {
        if (isset($this->tagCache[$name])) {
            return $this->tagCache[$name];
        }

        $tag = $this->stampTagRepository->findOneByName($name);
        if (null === $tag) {
            $tag = (new StampTag())
                ->setName($name)
                ->setSlug($this->slugger->slug($name)->lower()->toString());
            $this->entityManager->persist($tag);
        }

        return $this->tagCache[$name] = $tag;
    }

    private function getOrCreatePlace(string $name, ?string $url): StampPlace
    {
        $cacheKey = $name."\0".($url ?? '');
        if (isset($this->placeCache[$cacheKey])) {
            return $this->placeCache[$cacheKey];
        }

        $place = $this->stampPlaceRepository->findOneByNameAndUrl($name, $url);
        if (null === $place) {
            $place = (new StampPlace())
                ->setName($name)
                ->setUrl($url);
            $this->entityManager->persist($place);
        }

        return $this->placeCache[$cacheKey] = $place;
    }

    private function nullable(string $value): ?string
    {
        return '' === $value ? null : $value;
    }
}

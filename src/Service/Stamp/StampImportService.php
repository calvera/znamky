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
    ) {
    }

    /**
     * @return array{stamps: int, tags: int, places: int, files: int}
     */
    public function import(string $directory, bool $purge = false): array
    {
        $directory = rtrim($directory, '/\\');
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException(sprintf('Import directory does not exist: "%s".', $directory));
        }

        if ($purge) {
            $this->purgeCatalog();
        }

        $this->tagCache = [];
        $this->placeCache = [];

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

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->tagCache = [];
        $this->placeCache = [];

        return [
            'stamps' => $stampCount,
            'tags' => (int) $this->stampTagRepository->count([]),
            'places' => (int) $this->stampPlaceRepository->count([]),
            'files' => $filesImported,
        ];
    }

    private function purgeCatalog(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('TRUNCATE TABLE stamp_stamp_tag, stamp_stamp_place, stamps, stamp_tags, stamp_places RESTART IDENTITY CASCADE');
        $this->entityManager->clear();
        $this->tagCache = [];
        $this->placeCache = [];
    }

    private function importFile(string $path, StampCountry $defaultCountry, StampType $defaultType): int
    {
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Unable to open CSV file: "%s".', $path));
        }

        try {
            $header = fgetcsv($handle, length: 0, separator: ';', enclosure: '"', escape: '\\');
            if (false === $header) {
                return 0;
            }

            $header[0] = $this->stripBom((string) $header[0]);
            $header = array_map(static fn (?string $h): string => trim((string) $h, " \t\n\r\0\x0B\""), $header);
            $indexes = $this->mapIndexes($header);

            $count = 0;
            while (false !== ($row = fgetcsv($handle, length: 0, separator: ';', enclosure: '"', escape: '\\'))) {
                if ($this->isEmptyRow($row)) {
                    continue;
                }

                $this->importRow($row, $indexes, $defaultCountry, $defaultType);
                ++$count;

                if (0 === $count % self::BATCH_SIZE) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    $this->tagCache = [];
                    $this->placeCache = [];
                }
            }

            return $count;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<?string>      $row
     * @param array<string, int> $indexes
     */
    private function importRow(
        array $row,
        array $indexes,
        StampCountry $defaultCountry,
        StampType $defaultType,
    ): void {
        $number = (int) $this->cell($row, $indexes, 'Číslo');
        $name = $this->cell($row, $indexes, 'Název');
        if (0 === $number || '' === $name) {
            return;
        }

        $country = isset($indexes['Země'])
            ? StampCountry::fromCsv($this->cell($row, $indexes, 'Země'))
            : $defaultCountry;
        $type = isset($indexes['Typ'])
            ? StampType::fromCsv($this->cell($row, $indexes, 'Typ'))
            : $defaultType;

        $district = $this->nullableCell($row, $indexes, 'Okres');
        $region = $this->nullableCell($row, $indexes, 'Kraj');
        $kategorie = $this->nullableCell($row, $indexes, 'Kategorie');
        $placesCell = $this->nullableCell($row, $indexes, 'Prodejní místa (název a web)');

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

    /**
     * @param list<string> $header
     *
     * @return array<string, int>
     */
    private function mapIndexes(array $header): array
    {
        $indexes = [];
        foreach ($header as $i => $column) {
            if ('' !== $column) {
                $indexes[$column] = $i;
            }
        }

        if (!isset($indexes['Číslo'], $indexes['Název'])) {
            throw new \RuntimeException('CSV is missing required Číslo/Název columns.');
        }

        return $indexes;
    }

    /**
     * @param list<?string>      $row
     * @param array<string, int> $indexes
     */
    private function cell(array $row, array $indexes, string $column): string
    {
        if (!isset($indexes[$column])) {
            return '';
        }

        return trim((string) ($row[$indexes[$column]] ?? ''));
    }

    /**
     * @param list<?string>      $row
     * @param array<string, int> $indexes
     */
    private function nullableCell(array $row, array $indexes, string $column): ?string
    {
        $value = $this->cell($row, $indexes, $column);

        return '' === $value ? null : $value;
    }

    /**
     * @param list<?string> $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (null !== $value && '' !== trim($value)) {
                return false;
            }
        }

        return true;
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}

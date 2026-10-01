<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StampPlace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StampPlace>
 */
class StampPlaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StampPlace::class);
    }

    public function findOneByNameAndUrl(string $name, ?string $url): ?StampPlace
    {
        return $this->findOneBy([
            'catalogKey' => StampPlace::buildCatalogKey($name, $url),
        ]);
    }

    /**
     * @return list<StampPlace>
     */
    public function findNeedingGeocode(bool $force = false): array
    {
        $qb = $this->createQueryBuilder('p')->orderBy('p.id', 'ASC');
        if (!$force) {
            $qb->andWhere('p.latitude IS NULL OR p.longitude IS NULL');
        }

        /** @var list<StampPlace> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}

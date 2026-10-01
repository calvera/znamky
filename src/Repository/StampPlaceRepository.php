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
     * @return \Traversable<int, StampPlace>
     */
    public function iterateNeedingGeocode(bool $force = false): \Traversable
    {
        $qb = $this->createQueryBuilder('p')->orderBy('p.id', 'ASC');
        if (!$force) {
            $qb->andWhere('p.latitude IS NULL OR p.longitude IS NULL');
        }

        /** @var \Traversable<int, StampPlace> $result */
        $result = $qb->getQuery()->toIterable();

        return $result;
    }
}

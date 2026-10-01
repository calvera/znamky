<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Stamp;
use App\Enum\StampCountry;
use App\Enum\StampType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Stamp>
 */
class StampRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stamp::class);
    }

    public function findOneByIdentity(StampCountry $country, StampType $type, int $number): ?Stamp
    {
        return $this->findOneBy([
            'country' => $country,
            'type' => $type,
            'number' => $number,
        ]);
    }

    /**
     * @return list<Stamp>
     */
    public function findNeedingGeocode(bool $force = false): array
    {
        $qb = $this->createQueryBuilder('s')->orderBy('s.id', 'ASC');
        if (!$force) {
            $qb->andWhere('s.latitude IS NULL OR s.longitude IS NULL');
        }

        /** @var list<Stamp> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}

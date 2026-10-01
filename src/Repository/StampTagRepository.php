<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StampTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StampTag>
 */
class StampTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StampTag::class);
    }

    public function findOneByName(string $name): ?StampTag
    {
        return $this->findOneBy(['name' => $name]);
    }
}

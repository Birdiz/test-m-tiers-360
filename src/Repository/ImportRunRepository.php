<?php

namespace App\Repository;

use App\Entity\ImportRun;
use App\Entity\ImportRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportRun>
 */
class ImportRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportRun::class);
    }

    public function lastCompletedBefore(ImportRun $run): ?ImportRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :completed')
            ->andWhere('r.id < :run')
            ->setParameter('completed', ImportRunStatus::Completed)
            ->setParameter('run', $run->getId())
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

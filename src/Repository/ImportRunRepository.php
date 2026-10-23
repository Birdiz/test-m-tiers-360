<?php

namespace App\Repository;

use App\Entity\ImportRun;
use App\Entity\ImportRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
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
        return $this->getEntityManager()
            ->createQuery('SELECT r FROM '.ImportRun::class.' r WHERE r.status = :completed AND r.id < :run ORDER BY r.id DESC')
            ->setParameter('completed', ImportRunStatus::Completed)
            ->setParameter('run', $run->getId())
            ->setMaxResults(1)
            // An explicit hydration mode lets phpstan-doctrine infer ?ImportRun from the DQL.
            ->getOneOrNullResult(Query::HYDRATE_OBJECT);
    }

    public function lastFinished(): ?ImportRun
    {
        return $this->findOneBy(['status' => [ImportRunStatus::Completed, ImportRunStatus::Incomplete, ImportRunStatus::Failed]], ['id' => 'DESC']);
    }

    public function lastCompleted(): ?ImportRun
    {
        return $this->findOneBy(['status' => ImportRunStatus::Completed], ['id' => 'DESC']);
    }
}

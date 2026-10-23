<?php

namespace App\Repository;

use App\Entity\ImportRun;
use App\Enum\ImportRunStatus;
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

    /**
     * Fails every run still running. A bulk query: run entities already loaded keep their old status.
     */
    public function failRunning(string $reason, \DateTimeImmutable $at): void
    {
        $this->getEntityManager()
            ->createQuery('UPDATE '.ImportRun::class.' r SET r.status = :failed, r.reason = :reason, r.finishedAt = :at WHERE r.status = :running')
            ->setParameter('failed', ImportRunStatus::Failed)
            ->setParameter('reason', $reason)
            ->setParameter('at', $at)
            ->setParameter('running', ImportRunStatus::Running)
            ->execute();
    }

    /**
     * Writes how the run ended without flushing, which a closed entity manager cannot do. The counters keep what the
     * last committed page wrote.
     */
    public function recordFailure(ImportRun $run): void
    {
        $this->getEntityManager()
            ->createQuery('UPDATE '.ImportRun::class.' r SET r.status = :status, r.reason = :reason, r.finishedAt = :at WHERE r.id = :run')
            ->setParameter('status', $run->getStatus())
            ->setParameter('reason', $run->getReason())
            ->setParameter('at', $run->getFinishedAt())
            ->setParameter('run', $run->getId())
            ->execute();
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

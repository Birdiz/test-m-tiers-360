<?php

namespace App\Repository;

use App\Entity\ImportRun;
use App\Entity\Operation;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Entity\Session;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Registration>
 */
class RegistrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Registration::class);
    }

    /**
     * @param list<string> $externalIds
     *
     * @return array<string, Registration> indexed by external id
     */
    public function findByExternalIds(Operation $operation, array $externalIds): array
    {
        return $this->createQueryBuilder('r', 'r.externalId')
            ->where('r.operation = :operation')
            ->andWhere('r.externalId IN (:ids)')
            ->setParameter('operation', $operation)
            ->setParameter('ids', $externalIds)
            ->getQuery()
            ->getResult();
    }

    public function countSeenIn(Operation $operation, ImportRun $run): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.operation = :operation')
            ->andWhere('r.lastSeenRunId = :run')
            ->setParameter('operation', $operation)
            ->setParameter('run', $run->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Removes what no run has seen since before $previous, i.e. what both $previous and the current run missed.
     *
     * @return int how many registrations were removed
     */
    public function removeNotSeenSince(Operation $operation, ImportRun $previous, \DateTimeImmutable $at): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'UPDATE '.Registration::class.' r SET r.removedAt = :at
             WHERE r.operation = :operation AND r.lastSeenRunId < :previous AND r.removedAt IS NULL',
        )
            ->setParameter('at', $at)
            ->setParameter('operation', $operation)
            ->setParameter('previous', $previous->getId())
            ->execute();
    }

    /**
     * @return list<Registration> in queue order: first come, first served in this session (ADR 0004)
     */
    public function findBySession(Session $session): array
    {
        return $this->findBy(['session' => $session], ['joinedSessionAt' => 'ASC', 'externalId' => 'ASC']);
    }

    /**
     * Active registrations of the session's operation, in other sessions, for these emails.
     *
     * @param list<string> $emails
     *
     * @return list<Registration>
     */
    public function findActiveElsewhere(Session $session, array $emails): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.operation = :operation')
            ->andWhere('r.session <> :session')
            ->andWhere('r.email IN (:emails)')
            ->andWhere('r.status = :submitted')
            ->andWhere('r.removedAt IS NULL')
            ->setParameter('operation', $session->getOperation())
            ->setParameter('session', $session)
            ->setParameter('emails', $emails)
            ->setParameter('submitted', RegistrationStatus::Submitted)
            ->getQuery()
            ->getResult();
    }
}

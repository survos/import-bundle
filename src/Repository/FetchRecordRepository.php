<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\ImportBundle\Entity\FetchPage;
use Survos\ImportBundle\Entity\FetchRecord;

/**
 * @extends ServiceEntityRepository<FetchRecord>
 */
class FetchRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FetchRecord::class);
    }

    /** @return list<FetchRecord> */
    public function forPage(FetchPage $page): array
    {
        return $this->findBy(['fetchPage' => $page], ['rowNumber' => 'ASC']);
    }

    /** @return list<FetchRecord> */
    public function forDataset(string $providerCode, string $datasetKey, string $kind, ?string $recordType = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.fetchPage', 'p')
            ->andWhere('r.providerCode = :provider')
            ->andWhere('r.datasetKey = :dataset')
            ->andWhere('r.kind = :kind')
            ->andWhere('p.status = :status')
            ->setParameter('provider', $providerCode)
            ->setParameter('dataset', $datasetKey)
            ->setParameter('kind', $kind)
            ->setParameter('status', FetchPage::STATUS_FETCHED)
            ->orderBy('p.pageNumber', 'ASC')
            ->addOrderBy('r.rowNumber', 'ASC');

        if ($recordType !== null) {
            $qb
                ->andWhere('r.recordType = :recordType')
                ->setParameter('recordType', $recordType);
        }

        return $qb->getQuery()->getResult();
    }
}

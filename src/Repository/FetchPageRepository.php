<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\ImportBundle\Entity\FetchPage;

/**
 * @extends ServiceEntityRepository<FetchPage>
 */
class FetchPageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FetchPage::class);
    }
}

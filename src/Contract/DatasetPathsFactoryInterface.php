<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Contract;

use Survos\ImportBundle\Model\DatasetPaths;

/**
 * Resolves canonical filesystem locations for a dataset.
 *
 * Implementations must be deterministic and synchronous.
 * No filesystem side effects (creation) should occur here.
 */
interface DatasetPathsFactoryInterface
{
    public function for(string $datasetKey): DatasetPaths;
}

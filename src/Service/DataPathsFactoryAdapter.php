<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service;

use Survos\DataBundle\Service\DataPaths;
use Survos\ImportBundle\Contract\DatasetPathsFactoryInterface;
use Survos\ImportBundle\Model\DatasetPaths as ImportDatasetPaths;

/**
 * Adapter to bridge survos/data-bundle DataPaths with import-bundle DatasetPathsFactoryInterface
 */
final class DataPathsFactoryAdapter implements DatasetPathsFactoryInterface
{
    public function __construct(
        private readonly DataPaths $dataPaths,
    ) {
    }

    public function for(string $datasetKey): ImportDatasetPaths
    {
        $rawDir        = $this->dataPaths->stageDir($datasetKey, 'raw');
        $normalizedDir = $this->dataPaths->stageDir($datasetKey, 'normalize');
        $termsDir      = $this->dataPaths->stageDir($datasetKey, 'terms');

        return new ImportDatasetPaths(
            datasetKey:           $datasetKey,
            datasetRoot:          $this->dataPaths->datasetDir($datasetKey),
            rawDir:               $rawDir,
            rawObjectPath:        $rawDir . '/obj.jsonl',
            normalizedDir:        $normalizedDir,
            normalizedObjectPath: $normalizedDir . '/obj.jsonl',
            termsDir:             $termsDir,
        );
    }
}

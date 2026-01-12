<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service;

use Museado\DataBundle\Service\DataPaths;
use Survos\ImportBundle\Contract\DatasetPathsFactoryInterface;
use Survos\ImportBundle\Model\DatasetPaths as ImportDatasetPaths;

/**
 * Adapter to bridge museado/data-bundle DataPaths with import-bundle DatasetPathsFactoryInterface
 */
final class DataPathsFactoryAdapter implements DatasetPathsFactoryInterface
{
    public function __construct(
        private readonly DataPaths $dataPaths,
    ) {
    }

    public function for(string $datasetKey): ImportDatasetPaths
    {
        $dataset = $this->dataPaths->dataset($datasetKey);
        
        return new ImportDatasetPaths(
            datasetKey: $datasetKey,
            datasetRoot: $dataset->dir,
            rawDir: $dataset->rawDir,
            rawObjectPath: $dataset->rawFile('obj.jsonl'),
            normalizedDir: $dataset->normalizeDir,
            normalizedObjectPath: $dataset->normalizeFile('obj.jsonl'),
            termsDir: $dataset->termsDir,
        );
    }
}
<?php

declare(strict_types=1);

namespace Survos\ImportBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\ImportBundle\Entity\FetchRecord;
use Survos\JsonlBundle\IO\JsonlWriter;

final class FetchRecordExporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function exportToJsonl(
        string $providerCode,
        string $datasetKey,
        string $kind,
        string $output,
        ?string $recordType = null,
    ): int {
        $writer = JsonlWriter::open($output, 'w');
        $count = 0;
        $seen = [];

        foreach ($this->entityManager->getRepository(FetchRecord::class)->forDataset($providerCode, $datasetKey, $kind, $recordType) as $record) {
            $row = \json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
            $token = isset($row['id']) && \is_scalar($row['id']) ? (string) $row['id'] : null;
            if (null !== $token && isset($seen[$token])) {
                continue;
            }
            if (null !== $token) {
                $seen[$token] = true;
            }
            $writer->write($row, $token);
            ++$count;
        }

        $writer->finish(markComplete: true);

        return $count;
    }
}

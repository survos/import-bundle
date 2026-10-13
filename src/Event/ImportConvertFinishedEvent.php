<?php

declare(strict_types=1);

namespace Survos\ImportBundle\Event;

/**
 * Dispatched after import:convert finishes profiling.
 */
final class ImportConvertFinishedEvent
{
    /**
     * @param string[] $tags
     */
    public function __construct(
        public readonly string $input,
        public readonly string $jsonlPath,
        public readonly string $profilePath,
        public readonly int $recordCount,
        public readonly string $dataset,
        public readonly array $tags = [],
        public readonly ?int $limit = null,
        public readonly ?string $zipPath = null,
        public readonly ?string $rootKey = null,
    ) {
    }
}

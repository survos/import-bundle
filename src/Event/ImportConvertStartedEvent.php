<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Event;

/**
 * Dispatched at the beginning of import:convert, *before* any rows are processed.
 *
 * Carries:
 *  - input:       original input path (may be .zip/.gz/.csv/.json/.jsonl)
 *  - jsonlPath:   final JSONL output path
 *  - profilePath: final profile JSON path
 *  - dataset:     dataset code (e.g. "wam", "marvel")
 *  - tags:        base tags (dataset, source:..., extra tags like wikidata/youtube)
 *  - limit:       optional max records to process
 *  - zipPath:     optional internal path in ZIP (file or directory)
 *  - rootKey:     optional root key for JSON array
 */
final class ImportConvertStartedEvent
{
    /**
     * @param string   $input
     * @param string   $jsonlPath
     * @param string   $profilePath
     * @param ?string  $dataset
     * @param string[] $tags
     * @param ?int     $limit
     * @param ?string  $zipPath
     * @param ?string  $rootKey
     */
    public function __construct(
        public readonly string $input,
        public readonly string $jsonlPath,
        public readonly string $profilePath,
        public readonly ?string $dataset,
        public readonly array $tags = [],
        public readonly ?int $limit = null,
        public readonly ?string $zipPath = null,
        public readonly ?string $rootKey = null,
    ) {
    }
}

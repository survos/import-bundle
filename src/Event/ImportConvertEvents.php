<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched at the start of import:convert, before any records are processed.
 */
final class ImportConvertStartedEvent extends Event
{
    /**
     * @param string[] $tags
     */
    public function __construct(
        public readonly string $input,        // original input path (csv/json/jsonl/zip/gz)
        public readonly string $jsonlPath,    // target JSONL path
        public readonly string $profilePath,  // target profile path
        public readonly ?string $dataset = null, // dataset code (e.g. "wam", "marvel")
        public readonly array $tags = [],         // generic tags (dataset, source, etc.)
        public readonly ?int $limit = null,       // --limit
        public readonly ?string $zipPath = null,  // --zip-path
        public readonly ?string $rootKey = null,  // --root-key
    ) {
    }
}

/**
 * Dispatched for each record during import:convert, *before* it is written to JSONL.
 *
 * Listeners can:
 *  - mutate $row in-place
 *  - set $row = null to drop the record
 *  - set $status (e.g. STATUS_DUPLICATE) for diagnostics / filtering
 */
final class ImportConvertRowEvent extends Event
{
    public const STATUS_OKAY      = 'okay';
    public const STATUS_SKIP      = 'skip';
    public const STATUS_DUPLICATE = 'duplicate';

    /**
     * @param array<string,mixed>|null $row
     * @param string[]                 $tags
     */
    public function __construct(
        public ?array $row,                  // if listener sets to null => row is rejected
        public readonly string $input,       // original input path (CSV/JSON/JSONL/ZIP/GZ)
        public readonly ?string $format = null,   // 'csv', 'json', 'jsonl', 'json_dir', ...
        public readonly ?int $index = null,       // 0-based record index if known
        public readonly ?string $dataset = null,  // dataset code (e.g. "wam", "marvel")
        public array $tags = [],                 // generic tags (dataset, format:*, source:*, wikidata, youtube, ...)
        public ?string $status = null,           // see STATUS_* constants
    ) {
    }
}

/**
 * Dispatched at the end of import:convert, after JSONL + profile have been written.
 */
final class ImportConvertFinishedEvent extends Event
{
    /**
     * @param string[] $tags
     */
    public function __construct(
        public readonly string $input,        // original input path
        public readonly string $jsonlPath,    // final JSONL used for profile
        public readonly string $profilePath,  // profile path
        public readonly int $recordCount,     // records seen during profiling
        public readonly ?string $dataset = null, // dataset code (e.g. "wam", "marvel")
        public readonly array $tags = [],         // generic tags (dataset, source, etc.)
        public readonly ?int $limit = null,       // --limit
        public readonly ?string $zipPath = null,  // --zip-path
        public readonly ?string $rootKey = null,  // --root-key
    ) {
    }
}

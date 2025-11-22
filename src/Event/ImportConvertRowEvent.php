<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

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
        public readonly ?string $format = null, // 'csv', 'json', 'jsonl', 'json_dir', ...
        public readonly ?int $index = null,      // 0-based record index if known
        public readonly ?string $tag = null,     // primary tag (from --tag)
        public array $tags = [],                 // extra tags (dataset, source, etc.)
        public ?string $status = null,           // see STATUS_* constants
    ) {
    }
}

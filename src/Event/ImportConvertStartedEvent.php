<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched at the start of import:convert, before any records are processed.
 */
final class ImportConvertStartedEvent extends Event
{
    public function __construct(
        public readonly string $input,       // original input path (csv/json/jsonl/zip/gz)
        public readonly string $jsonlPath,   // target JSONL path
        public readonly string $profilePath, // target profile path
        public readonly ?string $tag,        // --tag
        public readonly ?int $limit,         // --limit
        public readonly ?string $zipPath,    // --zip-path
        public readonly ?string $rootKey,    // --root-key
    ) {
    }
}

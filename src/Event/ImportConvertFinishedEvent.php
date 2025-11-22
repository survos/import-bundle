<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched at the end of import:convert, after JSONL + profile have been written.
 */
final class ImportConvertFinishedEvent extends Event
{
    public function __construct(
        public readonly string $input,        // original input path
        public readonly string $jsonlPath,    // final JSONL used for profile
        public readonly string $profilePath,  // profile path
        public readonly int $recordCount,     // records seen during profiling
        public readonly ?string $tag,         // --tag
        public readonly ?int $limit,          // --limit
        public readonly ?string $zipPath,     // --zip-path
        public readonly ?string $rootKey,     // --root-key
    ) {
    }
}

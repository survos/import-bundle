<?php

declare(strict_types=1);

namespace Survos\ImportBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after import:convert finishes profiling.
 *
 * It fires for every conversion, whatever the stage (raw, normalize, ai, enrich). A listener that
 * derives files from the normalized core (pages, companion cores) must check $stage: reacting to an
 * enrich conversion rewrites normalize-stage files after enrich, which then look newer than enrich's
 * own output.
 */
final class ImportConvertFinishedEvent extends Event
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
        /** The --stage the conversion ran at: raw, normalize, ai or enrich. */
        public readonly string $stage = 'normalize',
    ) {
    }
}

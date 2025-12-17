<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Event;

final class ImportConvertRowEvent
{
    public const STATUS_OKAY = 'okay';

    /**
     * @param array<string,mixed>|null $row
     * @param string[]                $tags
     */
    public function __construct(
        public ?array  $row,
        public string  $input,
        public string  $format,
        public int     $index,
        public ?string $dataset,
        public array   $tags = [],
        public ?string $status = self::STATUS_OKAY,

        /**
         * Optional profile file to drive transforms on pass 2 (e.g. split lists).
         * If null, listeners should do nothing.
         */
        public ?string $applyProfilePath = null,
    ) {
    }
}

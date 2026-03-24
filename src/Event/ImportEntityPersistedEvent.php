<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class ImportEntityPersistedEvent extends Event
{
    /** @param array<string, mixed> $row */
    public function __construct(
        public readonly object $entity,
        public array $row,
        public readonly string $entityClass,
        public readonly ?string $datasetKey = null,
        public readonly ?string $tenant = null,
        public readonly ?string $sourcePath = null,
        public readonly array $context = [],
    ) {
    }
}

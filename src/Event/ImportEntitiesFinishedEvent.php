<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class ImportEntitiesFinishedEvent extends Event
{
    public function __construct(
        public readonly string $entityClass,
        public readonly int $count,
        public readonly ?string $datasetKey = null,
        public readonly ?string $tenant = null,
        public readonly ?string $sourcePath = null,
        public readonly array $context = [],
    ) {
    }
}

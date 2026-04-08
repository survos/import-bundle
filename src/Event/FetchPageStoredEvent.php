<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Event;

use Survos\ImportBundle\Entity\FetchPage;

final class FetchPageStoredEvent
{
    public function __construct(
        public FetchPage $page,
        public ?string $archivePath,
        public int $recordCount,
    ) {
    }
}

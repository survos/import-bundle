<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Event;

use Survos\ImportBundle\Entity\FetchPage;

final class FetchPageFetchedEvent
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];
    public ?string $archivePath = null;
    public ?string $contentType = null;

    public function __construct(
        public FetchPage $page,
        public string $body,
        public array $headers = [],
        public int $statusCode = 200,
    ) {
    }
}

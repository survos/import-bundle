<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Message;

final class FetchPageMessage
{
    public function __construct(
        public readonly int $fetchPageId,
    ) {
    }
}

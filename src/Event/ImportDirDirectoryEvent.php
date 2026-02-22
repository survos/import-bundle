<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Event;

use Survos\ImportBundle\Model\Directory;
use Symfony\Contracts\EventDispatcher\Event;

final class ImportDirDirectoryEvent extends Event
{
    public function __construct(
        public Directory $directory,
        public readonly int $probeLevel,
    ) {
    }
}

<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Event;

use Survos\ImportBundle\Model\File;
use Symfony\Contracts\EventDispatcher\Event;

final class ImportDirFileEvent extends Event
{
    public function __construct(
        public File $file,
        public readonly int $probeLevel,
        public readonly float $audioSimilarity,
    ) {
    }
}

<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Contract;

use Survos\ImportBundle\Model\FinderFileInfo;

interface HasFileInfoInterface
{
    public function getFileInfo(): FinderFileInfo;

    public function setFileInfo(FinderFileInfo $fileInfo): void;
}

<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Model;

trait FileInfoTrait
{
    public FinderFileInfo $fileInfo;

    public function getFileInfo(): FinderFileInfo
    {
        return $this->fileInfo;
    }

    public function setFileInfo(FinderFileInfo $fileInfo): void
    {
        $this->fileInfo = $fileInfo;
    }
}

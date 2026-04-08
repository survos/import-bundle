<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Contract;

interface FetchPagesInterface
{
    public static function getFetchProviderCode(): string;

    public function getId(): string|int|null;
}

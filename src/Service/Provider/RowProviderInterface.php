<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service\Provider;

interface RowProviderInterface
{
    public function supports(string $ext): bool;

    /**
     * @return \Generator<array<string,mixed>>
     */
    public function iterate(string $path, ProviderContext $ctx): \Generator;
}

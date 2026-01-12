<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Contract;

interface DatasetContextInterface
{
    public function set(string $dataset): void;

    public function has(): bool;

    public function getOrNull(): ?string;

    public function get(): string;
}

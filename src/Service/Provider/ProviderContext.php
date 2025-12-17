<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service\Provider;

use Symfony\Component\Console\Style\SymfonyStyle;

final class ProviderContext
{
    public function __construct(
        public readonly ?SymfonyStyle $io = null,
        public readonly ?string $rootKey = null,
        public readonly ?\Closure $onHeader = null,
    ) {
    }

    public function withOnHeader(?callable $onHeader): self
    {
        return new self(
            io: $this->io,
            rootKey: $this->rootKey,
            onHeader: $onHeader ? \Closure::fromCallable($onHeader) : null,
        );
    }
}

<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service;

final class FetchAwareEntityRegistry
{
    /**
     * @param array<string, class-string> $map
     */
    public function __construct(
        private readonly array $map = [],
    ) {
    }

    /**
     * @return array<string, class-string>
     */
    public function all(): array
    {
        return $this->map;
    }

    public function classFor(string $providerCode): ?string
    {
        return $this->map[$providerCode] ?? null;
    }
}

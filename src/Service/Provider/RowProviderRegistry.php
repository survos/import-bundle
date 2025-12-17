<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service\Provider;

use LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class RowProviderRegistry
{
    /**
     * @param iterable<RowProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('survos.import.row_provider')]
        private readonly iterable $providers,
    ) {
    }

    /**
     * @return \Generator<array<string,mixed>>
     */
    public function iterate(string $path, string $ext, ProviderContext $ctx): \Generator
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($ext)) {
                return $provider->iterate($path, $ctx);
            }
        }

        throw new LogicException(sprintf('No row provider supports extension "%s" for "%s".', $ext, $path));
    }
}

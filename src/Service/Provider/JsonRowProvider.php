<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service\Provider;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('survos.import.row_provider')]
final class JsonRowProvider implements RowProviderInterface
{
    public function supports(string $ext): bool
    {
        return $ext === 'json';
    }

    public function iterate(string $path, ProviderContext $ctx): \Generator
    {
        $contents = \file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read JSON file "%s".', $path));
        }

        $decoded = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('JSON root must be an object or array.');
        }

        $items = $decoded;

        if ($ctx->rootKey !== null) {
            if (!\array_key_exists($ctx->rootKey, $decoded)) {
                throw new \RuntimeException(sprintf(
                    'Root key "%s" not found in JSON. Available keys: %s',
                    $ctx->rootKey,
                    implode(', ', array_keys($decoded))
                ));
            }
            $items = $decoded[$ctx->rootKey];
            if (!\is_array($items)) {
                throw new \RuntimeException(sprintf('Value at root key "%s" is not an array.', $ctx->rootKey));
            }
        }

        if (\array_is_list($items)) {
            foreach ($items as $item) {
                if (\is_array($item)) {
                    yield $item;
                }
            }
            return;
        }

        // Object => single row
        yield $items;
    }
}

<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service\Provider;

use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('survos.import.row_provider')]
final class JsonlRowProvider implements RowProviderInterface
{
    public function supports(string $ext): bool
    {
        return $ext === 'jsonl';
    }

    public function iterate(string $path, ProviderContext $ctx): \Generator
    {
        $reader = JsonlReader::open($path);

        foreach ($reader as $decoded) {
            if (\is_array($decoded)) {
                yield $decoded;
            } else {
                // Keep behavior similar to previous code path
                yield ['raw' => $decoded];
            }
        }
    }
}

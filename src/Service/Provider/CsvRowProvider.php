<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service\Provider;

use League\Csv\Reader as CsvReader;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('survos.import.row_provider')]
final class CsvRowProvider implements RowProviderInterface
{
    public function supports(string $ext): bool
    {
        return $ext === 'csv';
    }

    public function iterate(string $path, ProviderContext $ctx): \Generator
    {
        $firstChunk = \file_get_contents($path, false, null, 0, 4096) ?: '';
        $delimiter  = \str_contains($firstChunk, "\t") ? "\t" : ',';

        if ($ctx->io) {
            $ctx->io->note(sprintf('Detected CSV delimiter: %s', $delimiter === "\t" ? '\\t (TAB)' : '"," (comma)'));
        }

        $csv = CsvReader::from($path, 'r');
        $csv->setDelimiter($delimiter);
        $csv->setHeaderOffset(0);

        $rawHeader = $csv->getHeader();
        $headerMap = [];

        foreach ($rawHeader as $name) {
            $normalized = $this->normalizeHeaderName((string) $name);
            $headerMap[(string) $name] = $normalized;

            if ($ctx->onHeader) {
                ($ctx->onHeader)($normalized, (string) $name);
            }

        }

        foreach ($csv->getRecords() as $record) {
            if (!\is_array($record)) {
                continue;
            }

            $normalizedRow = [];
            foreach ($record as $k => $v) {
                $k = (string) $k;
                $normKey = $headerMap[$k] ?? $k;
                $normalizedRow[$normKey] = $v;
            }

            yield $normalizedRow;
        }
    }

    private function normalizeHeaderName(string $name): string
    {
        $name  = \trim($name);
        $parts = \preg_split('/[^A-Za-z0-9]+/', $name, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return 'field';
        }

        $parts = \array_map(static fn($p) => \strtolower($p), $parts);

        $camel = \array_shift($parts);
        foreach ($parts as $p) {
            $camel .= \ucfirst($p);
        }

        if (!\preg_match('/^[A-Za-z_]/', $camel)) {
            $camel = '_' . $camel;
        }

        return $camel;
    }
}

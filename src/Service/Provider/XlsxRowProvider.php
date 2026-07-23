<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service\Provider;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('survos.import.row_provider')]
final class XlsxRowProvider implements RowProviderInterface
{
    public function supports(string $ext): bool
    {
        return $ext === 'xlsx' || $ext === 'xls';
    }

    public function iterate(string $path, ProviderContext $ctx): \Generator
    {
        if (!class_exists(IOFactory::class)) {
            throw new \RuntimeException(
                'Excel support requires phpoffice/phpspreadsheet. Install it with: composer require phpoffice/phpspreadsheet'
            );
        }

        // No setReadDataOnly(true): that skips cell styles, so a date-formatted cell's
        // number format is unknown and toArray()'s $formatData falls back to the raw Excel
        // serial (e.g. 44829) instead of a formatted date string.
        $sheet = IOFactory::createReaderForFile($path)->load($path)->getActiveSheet();

        $rows = $sheet->toArray(null, true, true, false);
        $rawHeader = array_shift($rows) ?? [];

        $headerKeys = [];
        foreach ($rawHeader as $name) {
            $name = trim((string) $name);
            $normalized = $this->normalizeHeaderName($name);
            $headerKeys[] = $normalized;

            if ($ctx->onHeader) {
                ($ctx->onHeader)($normalized, $name);
            }
        }

        foreach ($rows as $record) {
            $normalizedRow = [];
            $blank = true;
            foreach ($record as $i => $value) {
                $key = $headerKeys[$i] ?? (string) $i;
                if ($value !== null && $value !== '') {
                    $blank = false;
                }
                $normalizedRow[$key] = $value;
            }

            if ($blank) {
                continue; // trailing/blank sheet rows are common in xlsx exports
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

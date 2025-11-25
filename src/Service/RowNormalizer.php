<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service;

/**
 * Heuristic value normalizer for rows coming from CSV / JSON.
 *
 * Responsibilities:
 *  - Convert empty strings to null.
 *  - Convert "true"/"false" (case-insensitive) to bool.
 *  - Convert integer-like strings to int.
 *  - Convert float-like strings to float.
 *  - Convert comma/pipe-separated lists in multi-valued fields to arrays of strings.
 */
final class RowNormalizer
{
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function normalizeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            // Only normalize scalars; leave arrays/objects alone.
            if (!\is_scalar($value)) {
                continue;
            }

            // We only care to transform strings; ints/floats/bools can pass through.
            if (!\is_string($value)) {
                continue;
            }

            $trim = \trim($value);

            // Empty => null
            if ($trim === '') {
                $row[$key] = null;
                continue;
            }

            // Multi-valued lists first (so "Horror,Action,Comedy" becomes array).
            if (\str_contains($trim, ',') || \str_contains($trim, '|')) {
                if ($this->isMultiValueFieldName($key)) {
                    $delimiter = \str_contains($trim, '|') ? '|' : ',';
                    $parts     = \array_map('trim', \explode($delimiter, $trim));
                    $parts     = \array_values(\array_filter($parts, static fn($p) => $p !== ''));

                    $row[$key] = $parts;
                    continue;
                }
            }

            $lower = \strtolower($trim);

            // Booleans
            if ($lower === 'true' || $lower === 'false') {
                $row[$key] = ($lower === 'true');
                continue;
            }

            // Integer
            if (\preg_match('/^-?\d+$/', $trim) === 1) {
                $row[$key] = (int) $trim;
                continue;
            }

            // Float
            if (\preg_match('/^-?\d+\.\d+$/', $trim) === 1) {
                $row[$key] = (float) $trim;
                continue;
            }

            // Fallback: trimmed string
            $row[$key] = $trim;
        }

        return $row;
    }

    private function isMultiValueFieldName(string $key): bool
    {
        $key = \strtolower($key);

        // Explicit list for common fields; add as we encounter them.
        $explicit = [
            'tags',
            'genres',
            'actors',
            'characters',
            'aliases',
            'partners',
            'powers',
            'categories',
            'keywords',
        ];

        if (\in_array($key, $explicit, true)) {
            return true;
        }

        // Generic heuristic: plural-ish name.
        // We intentionally do NOT do anything if there is no comma/pipe in the value.
        return \str_ends_with($key, 's');
    }
}

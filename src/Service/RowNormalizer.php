<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service;

use function array_filter;
use function array_slice;
use function array_values;
use function explode;
use function preg_replace;
use function str_replace;
use function strtolower;
use function trim;
use function ucfirst;

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
        $normalized = [];

        foreach ($row as $key => $value) {
            $key = $this->normalizeKey((string) $key);

            // Only normalize scalars; leave arrays/objects alone.
            if (!\is_scalar($value)) {
                $normalized[$key] = $value;
                continue;
            }

            // We only care to transform strings; ints/floats/bools can pass through.
            if (!\is_string($value)) {
                $normalized[$key] = $value;
                continue;
            }

            $trim = trim($value);

            // Empty => null
            if ($trim === '') {
                $row[$key] = null;
                $normalized[$key] = null;
                continue;
            }

            // Multi-valued lists first (so "Horror,Action,Comedy" becomes array).
            if (\str_contains($trim, ',') || \str_contains($trim, '|')) {
                if ($this->isMultiValueFieldName($key)) {
                    $delimiter = \str_contains($trim, '|') ? '|' : ',';
                    $parts     = \array_map('trim', \explode($delimiter, $trim));
                    $parts     = \array_values(\array_filter($parts, static fn($p) => $p !== ''));

                    $normalized[$key] = $parts;
                    continue;
                }
            }

            $lower = strtolower($trim);

            // Booleans
            if ($lower === 'true' || $lower === 'false') {
                $normalized[$key] = ($lower === 'true');
                continue;
            }

            // Integer
            if (\preg_match('/^-?\d+$/', $trim) === 1) {
                $normalized[$key] = (int) $trim;
                continue;
            }

            // Float
            if (\preg_match('/^-?\d+\.\d+$/', $trim) === 1) {
                $normalized[$key] = (float) $trim;
                continue;
            }

            // Fallback: trimmed string
            $normalized[$key] = $trim;
        }

        return $normalized;
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

    private function normalizeKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return $key;
        }

        $key = str_replace(['-', '_', '.', '/'], ' ', $key);
        $key = preg_replace('/[^a-zA-Z0-9 ]+/', ' ', $key) ?? $key;
        $key = trim($key);
        if ($key === '') {
            return '';
        }

        $parts = array_values(array_filter(explode(' ', $key), static fn(string $p) => $p !== ''));
        if ($parts === []) {
            return '';
        }

        $first = strtolower($parts[0]);
        $rest = '';
        foreach (array_slice($parts, 1) as $part) {
            $rest .= ucfirst(strtolower($part));
        }

        return $first . $rest;
    }
}

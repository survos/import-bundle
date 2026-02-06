<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service;

use Survos\ImportBundle\IO\CsvWriter;
use Survos\JsonlBundle\IO\JsonlReader;
use RuntimeException;

use function file_get_contents;
use function implode;
use function is_array;
use function is_file;
use function is_scalar;
use function is_string;
use function json_encode;
use function json_decode;
use function sprintf;
use function str_ends_with;
use function substr;

final class CsvProfileExporter
{
    /**
     * @return array{outputPath:string,recordCount:int}
     */
    public function exportFromProfile(
        string $profilePath,
        ?string $inputPath = null,
        ?string $outputPath = null,
        ?int $limit = null,
    ): array {
        if (!is_file($profilePath)) {
            throw new RuntimeException(sprintf('Profile file not found: %s', $profilePath));
        }

        $raw = file_get_contents($profilePath);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Unable to read profile file: %s', $profilePath));
        }

        $profile = json_decode($raw, true);
        if (!is_array($profile)) {
            throw new RuntimeException(sprintf('Profile JSON is invalid: %s', $profilePath));
        }

        $fields = $profile['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            throw new RuntimeException(sprintf('Profile has no fields: %s', $profilePath));
        }

        if ($inputPath === null || $inputPath === '') {
            $inputPath = is_string($profile['output'] ?? null) ? (string) $profile['output'] : null;
        }
        if ($inputPath === null || $inputPath === '') {
            throw new RuntimeException('Missing input path (provide --input or ensure profile contains "output").');
        }
        if (!is_file($inputPath)) {
            throw new RuntimeException(sprintf('Input file not found: %s', $inputPath));
        }

        if ($outputPath === null || $outputPath === '') {
            $outputPath = $this->defaultCsvPathFromInput($inputPath);
        }

        $fieldNames = [];
        $headers = [];
        foreach ($fields as $name => $stats) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $fieldNames[] = $name;
            $header = $name;
            if (is_array($stats)) {
                $original = $stats['originalName'] ?? null;
                if (is_string($original) && $original !== '') {
                    $header = $original;
                }
            }
            $headers[] = $header;
        }

        if ($fieldNames === []) {
            throw new RuntimeException(sprintf('Profile fields are invalid: %s', $profilePath));
        }

        $writer = CsvWriter::open($outputPath);
        $writer->setHeaders($headers);

        $count = 0;
        $reader = JsonlReader::open($inputPath);
        foreach ($reader as $row) {
            if (!is_array($row)) {
                continue;
            }

            $ordered = [];
            foreach ($fieldNames as $fieldName) {
                $ordered[] = $this->normalizeCsvValue($row[$fieldName] ?? null);
            }

            $writer->write($ordered);
            $count++;

            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        $writer->close();

        return [
            'outputPath' => $outputPath,
            'recordCount' => $count,
        ];
    }

    private function normalizeCsvValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $parts = [];
        foreach ($value as $item) {
            if (is_scalar($item) || $item === null) {
                $parts[] = (string) ($item ?? '');
                continue;
            }

            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return implode('|', $parts);
    }

    private function defaultCsvPathFromInput(string $inputPath): string
    {
        $path = $inputPath;
        $compressed = str_ends_with($path, '.gz');
        if ($compressed) {
            $path = substr($path, 0, -3);
        }

        if (str_ends_with($path, '.jsonl')) {
            $path = substr($path, 0, -6) . '.csv';
        } elseif (str_ends_with($path, '.json')) {
            $path = substr($path, 0, -5) . '.csv';
        } elseif (!str_ends_with($path, '.csv')) {
            $path .= '.csv';
        }

        if ($compressed) {
            $path .= '.gz';
        }

        return $path;
    }
}

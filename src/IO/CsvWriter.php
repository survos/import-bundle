<?php
declare(strict_types=1);

namespace Survos\ImportBundle\IO;

use League\Csv\Writer as LeagueCsvWriter;
use League\Csv\CannotInsertRecord;
use RuntimeException;
use SplFileObject;

use function array_keys;
use function array_merge;
use function fclose;
use function fgetcsv;
use function fopen;
use function fwrite;
use function implode;
use function is_file;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function trim;

final class CsvWriter
{
    private ?array $headers = null;
    private ?array $originalHeaders = null;
    private LeagueCsvWriter $csv;
    private string $file;

    private function __construct(
        private readonly string $filename,
        private readonly string $delimiter = ',',
        private readonly string $enclosure = '"',
        private readonly string $escape = '\\',
    ) {
        $this->file = $this->filename;
        $this->csv = LeagueCsvWriter::from($this->filename, 'w');
        $this->csv->setDelimiter($this->delimiter);
        $this->csv->setEnclosure($this->enclosure);
        $this->csv->setEscape($this->escape);
    }

    public static function open(string $filename, string $delimiter = ','): self
    {
        return new self($filename, $delimiter);
    }

    public function write(array $row): void
    {
        if ($this->headers === null) {
            $this->initializeHeaders($row);
        }

        // If row is already indexed (0-based), use it as-is
        if (array_keys($row) === range(0, count($row) - 1)) {
            $orderedRow = $row;
        } else {
            // Associative array - order according to headers
            $orderedRow = [];
            foreach ($this->headers as $header) {
                // Try exact match first, then case-insensitive match
                $value = $row[$header] ?? null;
                if ($value === null) {
                    // Case-insensitive lookup for normalized headers
                    foreach ($row as $key => $val) {
                        if (strtolower($key) === strtolower($header)) {
                            $value = $val;
                            break;
                        }
                    }
                }
                $orderedRow[] = $value ?? '';
            }
        }

        try {
            $this->csv->insertOne($orderedRow);
        } catch (CannotInsertRecord $e) {
            throw new RuntimeException(sprintf(
                'Failed writing CSV record to "%s": %s',
                $this->filename,
                $e->getMessage()
            ), 0, $e);
        }
    }

    public function writeHeadersFromSourceFile(string $sourceFile): void
    {
        if (!is_file($sourceFile)) {
            throw new RuntimeException(sprintf('Source file "%s" does not exist.', $sourceFile));
        }

        $handle = fopen($sourceFile, 'r');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Failed to open source file "%s".', $sourceFile));
        }

        $headers = fgetcsv($handle, 0, $this->delimiter);
        fclose($handle);

        if ($headers === false) {
            throw new RuntimeException(sprintf('Failed to read headers from source file "%s".', $sourceFile));
        }

        $headers = array_map('trim', $headers);
        $this->originalHeaders = $headers;
        $this->headers = $headers;

        try {
            $this->csv->insertOne($this->headers);
        } catch (CannotInsertRecord $e) {
            throw new RuntimeException(sprintf(
                'Failed writing CSV headers to "%s": %s',
                $this->filename,
                $e->getMessage()
            ), 0, $e);
        }
    }

    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
        $this->originalHeaders = $headers;

        try {
            $this->csv->insertOne($this->headers);
        } catch (CannotInsertRecord $e) {
            throw new RuntimeException(sprintf(
                'Failed writing CSV headers to "%s": %s',
                $this->filename,
                $e->getMessage()
            ), 0, $e);
        }
    }

    public function getHeaders(): ?array
    {
        return $this->headers;
    }

    public function getOriginalHeaders(): ?array
    {
        return $this->originalHeaders;
    }

    public function close(): void
    {
        // League CSV handles closing automatically
    }

    private function initializeHeaders(array $row): void
    {
        $this->headers = array_keys($row);
        $this->originalHeaders = $this->headers;

        try {
            $this->csv->insertOne($this->headers);
        } catch (CannotInsertRecord $e) {
            throw new RuntimeException(sprintf(
                'Failed writing CSV headers to "%s": %s',
                $this->filename,
                $e->getMessage()
            ), 0, $e);
        }
    }
}
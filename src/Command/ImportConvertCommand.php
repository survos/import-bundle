<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Command;

use League\Csv\Reader as CsvReader;
use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Survos\ImportBundle\Event\ImportConvertStartedEvent;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Service\JsonlProfilerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Convert CSV/JSON/JSONL (optionally from ZIP/GZIP) to JSONL and produce a profile JSON.
 *
 * Example:
 *   bin/console import:convert data/movies.csv
 *   # -> <dataDir>/movies.jsonl + <dataDir>/movies.profile.json
 */
#[AsCommand('import:convert', 'Convert CSV/JSON/JSONL to JSONL and generate a profile')]
final class ImportConvertCommand
{
    public function __construct(
        private readonly JsonlProfilerInterface $profiler,
        private readonly string $dataDir,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Input CSV/JSON/JSONL path (ZIP/GZ supported)')] string $input,
        #[Option('Override output JSONL path (defaults to <dataDir>/<base>.jsonl)')] ?string $output = null,
        #[Option('Max records to process (for convert/profile)')] ?int $limit = null,
        #[Option('Treat input as JSONL and only generate the profile')] bool $profileOnly = false,
        #[Option('Tag to store in profile.tags[] and for listeners')] ?string $tag = null,
        #[Option('If input is a ZIP file, extract only this internal path (file or directory)')] ?string $zipPath = null,
        #[Option('If input JSON has a root key containing the list (e.g. "products")')] ?string $rootKey = null,
    ): int {
        $io->title('Import / Convert');

        if (!\is_file($input)) {
            $io->error(sprintf('Input file "%s" does not exist.', $input));

            return Command::FAILURE;
        }

        $ext = \strtolower(\pathinfo($input, \PATHINFO_EXTENSION));
        $baseName = \pathinfo($input, \PATHINFO_FILENAME);

        // Normalize compressed inputs (zip/gz) to a real file or directory we can read.
        // $sourceInput: path to CSV/JSON/JSONL file OR a "records directory"
        // $sourceExt:   'csv' | 'json' | 'jsonl' | 'json_dir'
        [$sourceInput, $sourceExt] = $this->normalizeInput($input, $ext, $zipPath, $io);

        $jsonlPath = $output ?? $this->defaultJsonlPath($baseName);
        $profilePath = $this->defaultProfilePath($jsonlPath);

        // Start event
        if ($this->dispatcher) {
            $this->dispatcher->dispatch(
                new ImportConvertStartedEvent($input, $jsonlPath, $profilePath, $tag, $limit, $zipPath, $rootKey)
            );
        }

        if ($profileOnly) {
            $io->note('Profile-only mode; input is treated as JSONL.');
            $jsonlPath = $sourceInput;
        } elseif ($sourceExt === 'jsonl') {
            $io->note('Input already in JSONL format; no conversion needed.');
            $jsonlPath = $sourceInput;
        } elseif ($sourceExt === 'csv') {
            $io->section(sprintf('Converting CSV to JSONL (from %s)', $sourceInput));
            $recordCount = $this->convertCsvToJsonl($sourceInput, $jsonlPath, $limit, $io, $input, $tag);
            $io->success(sprintf('Converted %d records to %s', $recordCount, $jsonlPath));
        } elseif ($sourceExt === 'json') {
            $io->section(sprintf('Converting JSON array to JSONL (from %s)', $sourceInput));
            $recordCount = $this->convertJsonArrayToJsonl($sourceInput, $jsonlPath, $limit, $rootKey, $io, $input, $tag);
            $io->success(sprintf('Converted %d records to %s', $recordCount, $jsonlPath));
        } elseif ($sourceExt === 'json_dir') {
            $io->section(sprintf('Converting JSON records directory to JSONL (from %s)', $sourceInput));
            $recordCount = $this->convertJsonRecordsDirToJsonl($sourceInput, $jsonlPath, $limit, $io, $input, $tag);
            $io->success(sprintf('Converted %d records to %s', $recordCount, $jsonlPath));
        } else {
            $io->error(sprintf('Unsupported input extension ".%s". Use CSV, JSON, or JSONL.', $sourceExt));

            return Command::FAILURE;
        }

        $io->section('Profiling JSONL');
        [$profile, $recordCount] = $this->buildProfile($jsonlPath, $limit);

        $tags = [];
        if ($tag !== null) {
            $tags[] = $tag;
        }

        $fullProfile = [
            'input'       => $input,      // original input path (may have been zip/gz)
            'output'      => $jsonlPath,  // actual JSONL used for profile
            'recordCount' => $recordCount,
            'tags'        => $tags,
            'fields'      => $profile,
        ];

        $this->ensureDir($profilePath);

        \file_put_contents(
            $profilePath,
            \json_encode($fullProfile, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)
        );

        $io->success(sprintf('Profile written to %s', $profilePath));

        // Finished event
        if ($this->dispatcher) {
            $this->dispatcher->dispatch(
                new ImportConvertFinishedEvent(
                    input: $input,
                    jsonlPath: $jsonlPath,
                    profilePath: $profilePath,
                    recordCount: $recordCount,
                    tag: $tag,
                    limit: $limit,
                    zipPath: $zipPath,
                    rootKey: $rootKey
                )
            );
        }

        return Command::SUCCESS;
    }

    private function defaultJsonlPath(string $baseName): string
    {
        $dir = \rtrim($this->dataDir, '/');

        return \sprintf('%s/%s.jsonl', $dir, $baseName);
    }

    private function defaultProfilePath(string $jsonlPath): string
    {
        $dir = \dirname($jsonlPath);
        $base = \pathinfo($jsonlPath, \PATHINFO_FILENAME);

        return \sprintf('%s/%s.profile.json', $dir, $base);
    }

    /**
     * Make sure the directory for a file exists.
     */
    private function ensureDir(string $filePath): void
    {
        $dir = \dirname($filePath);
        if ($dir !== '' && !\is_dir($dir)) {
            \mkdir($dir, 0777, true);
        }
    }

    /**
     * Before writing JSONL, remove previous file + index so we don't keep appending
     * across runs.
     */
    private function resetJsonlOutput(string $output): void
    {
        if (\is_file($output)) {
            \unlink($output);
        }
        $idx = $output . '.idx.json';
        if (\is_file($idx)) {
            \unlink($idx);
        }
    }

    /**
     * Normalize compressed inputs.
     *
     * - If ext is "zip": find CSV/JSON/JSONL inside and extract to a temp file,
     *   or treat a directory path as a "records directory".
     * - If ext is "gz":  gunzip to a temp file and infer the real extension.
     * - Otherwise: return original input/ext.
     *
     * @return array{0: string, 1: string} [sourceInputPath, sourceExt]
     */
    private function normalizeInput(string $input, string $ext, ?string $zipPath, SymfonyStyle $io): array
    {
        return match ($ext) {
            'zip' => $this->unpackZipInput($input, $zipPath, $io),
            'gz'  => $this->unpackGzipInput($input, $io),
            default => [$input, $ext],
        };
    }

    /**
     * Unpack a ZIP file and return:
     *   - a CSV/JSON/JSONL file path, or
     *   - a directory containing many JSON records (sourceExt = 'json_dir').
     *
     * If $filterPath is a directory, we treat it as "records directory".
     *
     * @return array{0: string, 1: string} [extractedPathOrDir, extOrJsonDir]
     */
    private function unpackZipInput(string $zipPath, ?string $filterPath, SymfonyStyle $io): array
    {
        $io->note(sprintf('ZIP input detected: %s', $zipPath));

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(sprintf('Unable to open ZIP file "%s".', $zipPath));
        }

        // If --zip-path was provided, check if it's a directory or a file.
        if ($filterPath) {
            $io->note(sprintf('Using --zip-path filter: "%s"', $filterPath));

            $normalizedFilter = \rtrim($filterPath, '/');

            // Try to locate the path exactly (could be file or dir).
            $index = $zip->locateName($normalizedFilter, \ZipArchive::FL_NOCASE);

            // If not found, try with trailing slash (directory entry).
            if ($index === false) {
                $index = $zip->locateName($normalizedFilter . '/', \ZipArchive::FL_NOCASE);
            }

            if ($index !== false) {
                $name = $zip->getNameIndex($index);
                if ($name === false) {
                    $zip->close();
                    throw new \RuntimeException(sprintf(
                        'Could not read entry at index %d in ZIP "%s".',
                        $index,
                        $zipPath
                    ));
                }

                // Directory case: name ends with "/"
                if (\str_ends_with($name, '/')) {
                    $dirName = $name; // e.g. "marvel-search-master/records/"

                    $io->note(sprintf('ZIP path "%s" is a directory; importing all JSON records under it.', $dirName));

                    $tmpDir = \sys_get_temp_dir() . '/import_bundle_zip_dir_' . \uniqid('', true);
                    \mkdir($tmpDir, 0777, true);

                    $fileNames = [];

                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $n = $zip->getNameIndex($i);
                        if ($n === false) {
                            continue;
                        }

                        // Only files inside that directory
                        if (!\str_starts_with($n, $dirName)) {
                            continue;
                        }
                        if (\str_ends_with($n, '/')) {
                            // skip nested directories
                            continue;
                        }

                        $ext = \strtolower(\pathinfo($n, \PATHINFO_EXTENSION));
                        if ($ext !== 'json' && $ext !== 'jsonl' && $ext !== 'csv') {
                            continue;
                        }

                        $fileNames[] = $n;
                    }

                    if ($fileNames === []) {
                        $zip->close();
                        throw new \RuntimeException(sprintf(
                            'Directory "%s" inside ZIP "%s" does not contain any JSON/JSONL/CSV files.',
                            $dirName,
                            $zipPath
                        ));
                    }

                    if (!$zip->extractTo($tmpDir, $fileNames)) {
                        $zip->close();
                        throw new \RuntimeException(sprintf(
                            'Failed to extract files from directory "%s" in ZIP "%s".',
                            $dirName,
                            $zipPath
                        ));
                    }

                    $zip->close();

                    // Return the directory path and a special "json_dir" marker
                    $recordsDir = $tmpDir . '/' . \rtrim($dirName, '/');

                    return [$recordsDir, 'json_dir'];
                }

                // File case (exact file)
                $ext = \strtolower(\pathinfo($name, \PATHINFO_EXTENSION));

                $tmpDir = \sys_get_temp_dir() . '/import_bundle_zip_' . \uniqid('', true);
                \mkdir($tmpDir, 0777, true);

                if (!$zip->extractTo($tmpDir, $name)) {
                    $zip->close();
                    throw new \RuntimeException(sprintf(
                        'Failed to extract "%s" from ZIP file "%s".',
                        $name,
                        $zipPath
                    ));
                }

                $zip->close();

                $extractedPath = $tmpDir . '/' . $name;
                $io->note(sprintf('ZIP extracted via --zip-path: %s (.%s)', $extractedPath, $ext));

                return [$extractedPath, $ext];
            }

            // If we get here, we didn't find the exact path; treat as error.
            $zip->close();
            throw new \RuntimeException(sprintf(
                'File or directory "%s" not found inside ZIP "%s".',
                $filterPath,
                $zipPath
            ));
        }

        // No filter: fallback to heuristic mode (first CSV, then JSON, then JSONL).
        $candidateIndex = null;
        $candidateName  = null;
        $candidateExt   = null;

        $priority = ['csv', 'json', 'jsonl'];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            if (\str_ends_with($name, '/')) {
                continue; // skip directories
            }

            $ext = \strtolower(\pathinfo($name, \PATHINFO_EXTENSION));
            if (!\in_array($ext, $priority, true)) {
                continue;
            }

            $candidateIndex = $i;
            $candidateName  = $name;
            $candidateExt   = $ext;
            break;
        }

        if ($candidateIndex === null || $candidateName === null || $candidateExt === null) {
            $zip->close();
            throw new \RuntimeException(sprintf(
                'ZIP file "%s" does not contain a CSV, JSON, or JSONL file.',
                $zipPath
            ));
        }

        $io->note(sprintf('Using "%s" from ZIP (.%s)', $candidateName, $candidateExt));

        $tmpDir = \sys_get_temp_dir() . '/import_bundle_zip_' . \uniqid('', true);
        \mkdir($tmpDir, 0777, true);

        if (!$zip->extractTo($tmpDir, $candidateName)) {
            $zip->close();
            throw new \RuntimeException(sprintf(
                'Failed to extract "%s" from ZIP file "%s".',
                $candidateName,
                $zipPath
            ));
        }

        $zip->close();

        $extractedPath = $tmpDir . '/' . $candidateName;

        return [$extractedPath, $candidateExt];
    }

    /**
     * Unpack a GZIP file to a temp file and infer the real extension from the filename.
     *
     * @return array{0: string, 1: string} [extractedPath, ext]
     */
    private function unpackGzipInput(string $gzPath, SymfonyStyle $io): array
    {
        $io->note(sprintf('GZIP input detected: %s', $gzPath));

        $base = \pathinfo($gzPath, \PATHINFO_FILENAME); // e.g. "movies.csv" from "movies.csv.gz"
        $innerExt = \strtolower(\pathinfo($base, \PATHINFO_EXTENSION));

        if ($innerExt === '') {
            throw new \RuntimeException(sprintf(
                'Cannot infer underlying extension from GZIP file "%s". Expected something like ".csv.gz" or ".json.gz".',
                $gzPath
            ));
        }

        $tmpDir = \sys_get_temp_dir() . '/import_bundle_gz_' . \uniqid('', true);
        \mkdir($tmpDir, 0777, true);
        $outPath = $tmpDir . '/' . $base;

        $gz = \gzopen($gzPath, 'rb');
        if ($gz === false) {
            throw new \RuntimeException(sprintf('Unable to open GZIP file "%s".', $gzPath));
        }

        $out = \fopen($outPath, 'wb');
        if ($out === false) {
            \gzclose($gz);
            throw new \RuntimeException(sprintf('Unable to create temporary file "%s".', $outPath));
        }

        while (!\gzeof($gz)) {
            $chunk = \gzread($gz, 8192);
            if ($chunk === false) {
                break;
            }
            \fwrite($out, $chunk);
        }

        \gzclose($gz);
        \fclose($out);

        $io->note(sprintf('GZIP decompressed to %s (.%s)', $outPath, $innerExt));

        return [$outPath, $innerExt];
    }

    private function convertCsvToJsonl(
        string $input,
        string $output,
        ?int $limit,
        SymfonyStyle $io,
        string $originalInput,
        ?string $tag,
    ): int {
        // Sniff delimiter from the first line: tab vs comma
        $firstChunk = \file_get_contents($input, false, null, 0, 4096) ?: '';
        $delimiter = \str_contains($firstChunk, "\t") ? "\t" : ',';

        $io->note(sprintf('Detected CSV delimiter: %s', $delimiter === "\t" ? '\\t (TAB)' : '"," (comma)'));

        $csv = CsvReader::createFromPath($input, 'r');
        $csv->setDelimiter($delimiter);
        $csv->setHeaderOffset(0); // first row is header

        // Reset output so each run starts fresh
        $this->resetJsonlOutput($output);
        $writer = JsonlWriter::open($output);

        $count = 0;
        $index = 0;
        $format = 'csv';

        foreach ($csv->getRecords() as $record) {
            if (!\is_array($record)) {
                $io->warning('Skipping non-array CSV record.');
                continue;
            }

            $record = $this->applyRowCallbacks($record, $originalInput, $format, $tag, $index);
            $index++;

            if ($record === null) {
                continue;
            }

            $writer->write($record);
            $count++;

            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        $writer->close();

        return $count;
    }

    private function convertJsonArrayToJsonl(
        string $input,
        string $output,
        ?int $limit,
        ?string $rootKey,
        SymfonyStyle $io,
        string $originalInput,
        ?string $tag,
    ): int {
        $contents = \file_get_contents($input);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read JSON file "%s".', $input));
        }

        $decoded = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('JSON root must be an object or array.');
        }

        // If a rootKey is given, treat decoded[rootKey] as the list.
        if ($rootKey !== null) {
            if (!\array_key_exists($rootKey, $decoded)) {
                throw new \RuntimeException(sprintf(
                    'Root key "%s" not found in JSON. Available keys: %s',
                    $rootKey,
                    \implode(', ', \array_keys($decoded))
                ));
            }

            $items = $decoded[$rootKey];
            if (!\is_array($items)) {
                throw new \RuntimeException(sprintf(
                    'Value at root key "%s" is not an array.',
                    $rootKey
                ));
            }

            $io->note(sprintf('Using JSON root key "%s" with %d items (if fully loaded).', $rootKey, \count($items)));
        } else {
            // If no rootKey, assume the decoded root itself is the array.
            $items = $decoded;
            if (!\is_array($items)) {
                throw new \RuntimeException('JSON root must be an array when no rootKey is provided.');
            }
        }

        $this->resetJsonlOutput($output);
        $writer = JsonlWriter::open($output);

        $count = 0;
        $index = 0;
        $format = 'json';

        foreach ($items as $item) {
            if (!\is_array($item)) {
                $io->warning('Skipping non-object item in JSON array.');
                continue;
            }

            $item = $this->applyRowCallbacks($item, $originalInput, $format, $tag, $index);
            $index++;

            if ($item === null) {
                continue;
            }

            $writer->write($item);
            $count++;

            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        $writer->close();

        return $count;
    }

    /**
     * Convert a directory of JSON/JSONL/CSV "records" into JSONL:
     * - *.json  → each file is either a single object or an array of objects.
     * - *.jsonl → each line is a record (decoded→callback→encoded).
     * - *.csv   → we treat it as a standard CSV file (header row).
     */
    private function convertJsonRecordsDirToJsonl(
        string $dir,
        string $output,
        ?int $limit,
        SymfonyStyle $io,
        string $originalInput,
        ?string $tag,
    ): int {
        if (!\is_dir($dir)) {
            throw new \RuntimeException(sprintf('Records directory "%s" does not exist.', $dir));
        }

        $this->resetJsonlOutput($output);
        $writer = JsonlWriter::open($output);

        $count = 0;
        $index = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $ext = \strtolower($file->getExtension());
            $path = $file->getPathname();

            if ($ext === 'json') {
                $contents = \file_get_contents($path);
                if ($contents === false) {
                    $io->warning(sprintf('Unable to read JSON file "%s". Skipping.', $path));
                    continue;
                }

                $decoded = \json_decode($contents, true);
                if (!\is_array($decoded)) {
                    $io->warning(sprintf('JSON in "%s" is not an object/array. Skipping.', $path));
                    continue;
                }

                if (\array_is_list($decoded)) {
                    foreach ($decoded as $item) {
                        if (!\is_array($item)) {
                            $io->warning(sprintf('Non-object item in array in "%s". Skipping item.', $path));
                            continue;
                        }

                        $item = $this->applyRowCallbacks($item, $originalInput, 'json', $tag, $index);
                        $index++;

                        if ($item === null) {
                            continue;
                        }

                        $writer->write($item);
                        $count++;
                        if ($limit !== null && $count >= $limit) {
                            $writer->close();

                            return $count;
                        }
                    }
                } else {
                    // Single object per file
                    $decoded = $this->applyRowCallbacks($decoded, $originalInput, 'json', $tag, $index);
                    $index++;

                    if ($decoded !== null) {
                        $writer->write($decoded);
                        $count++;
                        if ($limit !== null && $count >= $limit) {
                            $writer->close();

                            return $count;
                        }
                    }
                }
            } elseif ($ext === 'jsonl') {
                $handle = \fopen($path, 'rb');
                if ($handle === false) {
                    $io->warning(sprintf('Unable to open JSONL file "%s". Skipping.', $path));
                    continue;
                }
                while (($line = \fgets($handle)) !== false) {
                    $line = \trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $decoded = \json_decode($line, true);
                    if (\is_array($decoded)) {
                        $decoded = $this->applyRowCallbacks($decoded, $originalInput, 'jsonl', $tag, $index);
                        $index++;

                        if ($decoded === null) {
                            continue;
                        }

                        $writer->write($decoded);
                        $count++;
                    } else {
                        $writer->write(['raw' => $line]);
                        $count++;
                    }

                    if ($limit !== null && $count >= $limit) {
                        \fclose($handle);
                        $writer->close();

                        return $count;
                    }
                }
                \fclose($handle);
            } elseif ($ext === 'csv') {
                $count += $this->appendCsvToJsonl($path, $writer, $limit, $count, $io, $originalInput, $tag, $index);
                if ($limit !== null && $count >= $limit) {
                    $writer->close();

                    return $count;
                }
            }

            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        $writer->close();

        return $count;
    }

    /**
     * Append CSV records to an already-open JsonlWriter.
     */
    private function appendCsvToJsonl(
        string $input,
        JsonlWriter $writer,
        ?int $limit,
        int $currentCount,
        SymfonyStyle $io,
        string $originalInput,
        ?string $tag,
        int &$index
    ): int {
        $firstChunk = \file_get_contents($input, false, null, 0, 4096) ?: '';
        $delimiter = \str_contains($firstChunk, "\t") ? "\t" : ',';

        $csv = CsvReader::createFromPath($input, 'r');
        $csv->setDelimiter($delimiter);
        $csv->setHeaderOffset(0);

        $count = $currentCount;
        $format = 'csv';

        foreach ($csv->getRecords() as $record) {
            if (!\is_array($record)) {
                $io->warning('Skipping non-array CSV record.');
                continue;
            }

            $record = $this->applyRowCallbacks($record, $originalInput, $format, $tag, $index);
            $index++;

            if ($record === null) {
                continue;
            }

            $writer->write($record);
            $count++;

            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        return $count - $currentCount;
    }

    /**
     * Apply row-level callbacks via ImportConvertRowEvent, if a dispatcher is available.
     *
     * @return array<string,mixed>|null  Returns the (possibly mutated) row, or null to skip it.
     */
    private function applyRowCallbacks(
        array $row,
        string $input,
        string $format,
        ?string $tag,
        int $index
    ): ?array {
        if ($this->dispatcher === null) {
            return $row;
        }

        $tags = [];
        if ($tag !== null) {
            $tags[] = $tag;
        }
        $tags[] = 'format:' . $format;
        $tags[] = 'source:' . \basename($input);

        $event = new ImportConvertRowEvent(
            row: $row,
            input: $input,
            format: $format,
            index: $index,
            tag: $tag,
            tags: $tags,
            status: ImportConvertRowEvent::STATUS_OKAY,
        );

        $this->dispatcher->dispatch($event);

        if ($event->row === null) {
            return null; // explicit rejection
        }

        if ($event->status !== null && $event->status !== ImportConvertRowEvent::STATUS_OKAY) {
            return null; // treated as rejected
        }

        return $event->row;
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: int}
     */
    private function buildProfile(string $jsonlPath, ?int $limit): array
    {
        $reader = new JsonlReader($jsonlPath);

        $rows = [];
        $count = 0;

        foreach ($reader as $row) {
            $rows[] = $row;
            $count++;

            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        $profile = $this->profiler->profile($rows);

        return [$profile, $count];
    }
}

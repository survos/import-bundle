<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Command;

use League\Csv\Reader as CsvReader;
use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Survos\ImportBundle\Event\ImportConvertStartedEvent;
use Survos\ImportBundle\Service\RowNormalizer;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Service\JsonlProfilerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand('import:convert', 'Convert CSV/JSON/JSONL to JSONL and generate a profile')]
final class ImportConvertCommand
{
    /** @var array<string,string> normalizedName => originalHeader */
    private array $fieldOriginalNames = [];

    /** @var string[] */
    private array $extraTags = [];

    private RowNormalizer $rowNormalizer;

    public function __construct(
        private readonly JsonlProfilerInterface $profiler,
        private readonly string $dataDir,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
        // Stateless helper – safe to construct here; can later be promoted to DI service.
        $this->rowNormalizer = new RowNormalizer();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Input CSV/JSON/JSONL path (ZIP/GZ supported)')]
        string $input,
        #[Option('Override output JSONL path (defaults to <dataDir>/<base>.jsonl)')]
        ?string $output = null,
        #[Option('Max records to process (for convert/profile)')]
        ?int $limit = null,
        #[Option('Treat input as JSONL and only generate the profile')]
        bool $profileOnly = false,
        #[Option('Dataset code (e.g. "wam", "marvel")')]
        ?string $dataset = null,
        #[Option('Additional tags (comma-separated, e.g. "wikidata,youtube")', name: 'tags')]
        ?string $tagsOption = null,
        #[Option('If input is a ZIP file, extract only this internal path (file or directory)')]
        ?string $zipPath = null,
        #[Option('If input JSON has a root key containing the list (e.g. "products")')]
        ?string $rootKey = null,
    ): int {
        $io->title('Import / Convert');

        if (!\is_file($input)) {
            $io->error(\sprintf('Input file "%s" does not exist.', $input));
            return Command::FAILURE;
        }

        $this->fieldOriginalNames = [];
        $this->extraTags          = $this->parseExtraTags($tagsOption);

        $ext      = \strtolower(\pathinfo($input, \PATHINFO_EXTENSION));
        $baseName = \pathinfo($input, \PATHINFO_FILENAME);
        $dataset ??= $baseName;

        [$sourceInput, $sourceExt] = $this->normalizeInput($input, $ext, $zipPath, $io);

        $jsonlPath   = $output ?? $this->defaultJsonlPath($dataset);
        $profilePath = $this->defaultProfilePath($jsonlPath);

        $baseTags = [];
        if ($dataset !== null) {
            $baseTags[] = $dataset;
        }
        $baseTags[] = 'source:' . \basename($input);
        $baseTags   = \array_values(\array_unique(\array_merge($baseTags, $this->extraTags)));

        if ($this->dispatcher) {
            $this->dispatcher->dispatch(
                new ImportConvertStartedEvent(
                    $input,
                    $jsonlPath,
                    $profilePath,
                    $dataset,
                    $baseTags,
                    $limit,
                    $zipPath,
                    $rootKey
                )
            );
        }

        if ($profileOnly) {
            $io->note('Profile-only mode; input is treated as JSONL.');
            $jsonlPath = $sourceInput;
        } elseif ($sourceExt === 'jsonl') {
            $io->note('Input already in JSONL format; no conversion needed.');
            $jsonlPath = $sourceInput;
        } elseif ($sourceExt === 'csv') {
            $io->section(\sprintf('Converting CSV to JSONL (from %s)', $sourceInput));
            $recordCount = $this->convertCsvToJsonl($sourceInput, $jsonlPath, $limit, $io, $input, $dataset);
            $io->success(\sprintf('Converted %d records to %s', $recordCount, $jsonlPath));
        } elseif ($sourceExt === 'json') {
            $io->section(\sprintf('Converting JSON array to JSONL (from %s)', $sourceInput));
            $recordCount = $this->convertJsonArrayToJsonl($sourceInput, $jsonlPath, $limit, $rootKey, $io, $input, $dataset);
            $io->success(\sprintf('Converted %d records to %s', $recordCount, $jsonlPath));
        } elseif ($sourceExt === 'json_dir') {
            $io->section(\sprintf('Converting JSON records directory to JSONL (from %s)', $sourceInput));
            $recordCount = $this->convertJsonRecordsDirToJsonl($sourceInput, $jsonlPath, $limit, $io, $input, $dataset);
            $io->success(\sprintf('Converted %d records to %s', $recordCount, $jsonlPath));
        } else {
            $io->error(\sprintf('Unsupported input extension ".%s". Use CSV, JSON, or JSONL.', $sourceExt));
            return Command::FAILURE;
        }

        $io->section('Profiling JSONL');
        [$fieldsProfile, $recordCount, $uniqueFields, $samples] = $this->buildProfile($jsonlPath, $limit);

        // Inject original header name if we know it
        foreach ($fieldsProfile as $name => &$stats) {
            if (isset($this->fieldOriginalNames[$name])) {
                $stats['originalName'] = $this->fieldOriginalNames[$name];
            }
        }
        unset($stats);

        $tags         = $baseTags;
        $uniqueFields = \array_values($uniqueFields); // ensure contiguous indexes

        if ($uniqueFields) {
            $io->note('PK-like unique fields: ' . \implode(', ', $uniqueFields));
        } else {
            $io->warning('No PK-like unique field detected (non-null, allowed chars, no duplicates).');
            $io->writeln('  → You may need to fix the profile logic or provide a separate id field.');
        }

        $fullProfile = [
            'input'        => $input,
            'output'       => $jsonlPath,
            'recordCount'  => $recordCount,
            'tags'         => $tags,
            'dataset'      => $dataset,
            'uniqueFields' => $uniqueFields,
            'fields'       => $fieldsProfile,
            'samples'      => $samples,
        ];

        $this->ensureDir($profilePath);
        \file_put_contents(
            $profilePath,
            \json_encode($fullProfile, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)
        );
        $io->success(\sprintf('Profile written to %s', $profilePath));

        if ($this->dispatcher) {
            $this->dispatcher->dispatch(
                new ImportConvertFinishedEvent(
                    $input,
                    $jsonlPath,
                    $profilePath,
                    $recordCount,
                    $dataset,
                    $tags,
                    $limit,
                    $zipPath,
                    $rootKey
                )
            );
        }

        return Command::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function defaultJsonlPath(string $baseName): string
    {
        $dir = \rtrim($this->dataDir, '/');
        return \sprintf('%s/%s.jsonl', $dir, $baseName);
    }

    private function defaultProfilePath(string $jsonlPath): string
    {
        $dir  = \dirname($jsonlPath);
        $base = \pathinfo($jsonlPath, \PATHINFO_FILENAME);
        return \sprintf('%s/%s.profile.json', $dir, $base);
    }

    private function ensureDir(string $filePath): void
    {
        $dir = \dirname($filePath);
        if ($dir !== '' && !\is_dir($dir)) {
            \mkdir($dir, 0o777, true);
        }
    }

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
     * @return string[]
     */
    private function parseExtraTags(?string $tagsOption): array
    {
        if ($tagsOption === null || $tagsOption === '') {
            return [];
        }

        $parts = \array_map('trim', \explode(',', $tagsOption));
        $parts = \array_filter($parts, static fn($t) => $t !== '');
        return \array_values(\array_unique($parts));
    }

    private function normalizeInput(string $input, string $ext, ?string $zipPath, SymfonyStyle $io): array
    {
        return match ($ext) {
            'zip' => $this->unpackZipInput($input, $zipPath, $io),
            'gz'  => $this->unpackGzipInput($input, $io),
            default => [$input, $ext],
        };
    }

    private function unpackZipInput(string $zipPath, ?string $filterPath, SymfonyStyle $io): array
    {
        $io->note(\sprintf('ZIP input detected: %s', $zipPath));
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(\sprintf('Unable to open ZIP file "%s".', $zipPath));
        }

        if ($filterPath) {
            $io->note(\sprintf('Using --zip-path filter: "%s"', $filterPath));
            $normalizedFilter = \rtrim($filterPath, '/');
            $index = $zip->locateName($normalizedFilter, \ZipArchive::FL_NOCASE);
            if ($index === false) {
                $index = $zip->locateName($normalizedFilter . '/', \ZipArchive::FL_NOCASE);
            }

            if ($index !== false) {
                $name = $zip->getNameIndex($index);
                if ($name === false) {
                    $zip->close();
                    throw new \RuntimeException(\sprintf('Could not read entry at index %d in ZIP "%s".', $index, $zipPath));
                }

                if (\str_ends_with($name, '/')) {
                    $dirName = $name;
                    $io->note(\sprintf('ZIP path "%s" is a directory; importing all JSON records under it.', $dirName));
                    $tmpDir = \sys_get_temp_dir() . '/import_bundle_zip_dir_' . \uniqid('', true);
                    \mkdir($tmpDir, 0o777, true);
                    $fileNames = [];

                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $n = $zip->getNameIndex($i);
                        if ($n === false) {
                            continue;
                        }
                        if (!\str_starts_with($n, $dirName) || \str_ends_with($n, '/')) {
                            continue;
                        }
                        $e = \strtolower(\pathinfo($n, \PATHINFO_EXTENSION));
                        if (!\in_array($e, ['json', 'jsonl', 'csv'], true)) {
                            continue;
                        }
                        $fileNames[] = $n;
                    }

                    if ($fileNames === []) {
                        $zip->close();
                        throw new \RuntimeException(\sprintf(
                            'Directory "%s" inside ZIP "%s" does not contain any JSON/JSONL/CSV files.',
                            $dirName,
                            $zipPath
                        ));
                    }

                    if (!$zip->extractTo($tmpDir, $fileNames)) {
                        $zip->close();
                        throw new \RuntimeException(\sprintf(
                            'Failed to extract files from directory "%s" in ZIP "%s".',
                            $dirName,
                            $zipPath
                        ));
                    }

                    $zip->close();
                    $recordsDir = $tmpDir . '/' . \rtrim($dirName, '/');
                    return [$recordsDir, 'json_dir'];
                }

                $ext = \strtolower(\pathinfo($name, \PATHINFO_EXTENSION));
                $tmpDir = \sys_get_temp_dir() . '/import_bundle_zip_' . \uniqid('', true);
                \mkdir($tmpDir, 0o777, true);
                if (!$zip->extractTo($tmpDir, $name)) {
                    $zip->close();
                    throw new \RuntimeException(\sprintf(
                        'Failed to extract "%s" from ZIP file "%s".',
                        $name,
                        $zipPath
                    ));
                }
                $zip->close();
                $extractedPath = $tmpDir . '/' . $name;
                $io->note(\sprintf('ZIP extracted via --zip-path: %s (.%s)', $extractedPath, $ext));

                return [$extractedPath, $ext];
            }

            $zip->close();
            throw new \RuntimeException(\sprintf(
                'File or directory "%s" not found inside ZIP "%s".',
                $filterPath,
                $zipPath
            ));
        }

        $candidateName = null;
        $candidateExt  = null;
        $priority      = ['csv', 'json', 'jsonl'];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || \str_ends_with($name, '/')) {
                continue;
            }
            $ext = \strtolower(\pathinfo($name, \PATHINFO_EXTENSION));
            if (!\in_array($ext, $priority, true)) {
                continue;
            }
            $candidateName = $name;
            $candidateExt  = $ext;
            break;
        }

        if ($candidateName === null || $candidateExt === null) {
            $zip->close();
            throw new \RuntimeException(\sprintf(
                'ZIP file "%s" does not contain a CSV, JSON, or JSONL file.',
                $zipPath
            ));
        }

        $io->note(\sprintf('Using "%s" from ZIP (.%s)', $candidateName, $candidateExt));
        $tmpDir = \sys_get_temp_dir() . '/import_bundle_zip_' . \uniqid('', true);
        \mkdir($tmpDir, 0o777, true);
        if (!$zip->extractTo($tmpDir, $candidateName)) {
            $zip->close();
            throw new \RuntimeException(\sprintf(
                'Failed to extract "%s" from ZIP file "%s".',
                $candidateName,
                $zipPath
            ));
        }
        $zip->close();

        $extractedPath = $tmpDir . '/' . $candidateName;
        return [$extractedPath, $candidateExt];
    }

    private function unpackGzipInput(string $gzPath, SymfonyStyle $io): array
    {
        $io->note(\sprintf('GZIP input detected: %s', $gzPath));
        $base     = \pathinfo($gzPath, \PATHINFO_FILENAME);
        $innerExt = \strtolower(\pathinfo($base, \PATHINFO_EXTENSION));

        if ($innerExt === '') {
            throw new \RuntimeException(\sprintf(
                'Cannot infer underlying extension from GZIP file "%s". Expected something like ".csv.gz" or ".json.gz".',
                $gzPath
            ));
        }

        $tmpDir  = \sys_get_temp_dir() . '/import_bundle_gz_' . \uniqid('', true);
        \mkdir($tmpDir, 0o777, true);
        $outPath = $tmpDir . '/' . $base;

        $gz = \gzopen($gzPath, 'rb');
        if ($gz === false) {
            throw new \RuntimeException(\sprintf('Unable to open GZIP file "%s".', $gzPath));
        }
        $out = \fopen($outPath, 'wb');
        if ($out === false) {
            \gzclose($gz);
            throw new \RuntimeException(\sprintf('Unable to create temporary file "%s".', $outPath));
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

        $io->note(\sprintf('GZIP decompressed to %s (.%s)', $outPath, $innerExt));
        return [$outPath, $innerExt];
    }

    // ------------------------------------------------------------------
    // Conversion routines
    // ------------------------------------------------------------------

    private function convertCsvToJsonl(
        string $input,
        string $output,
        ?int $limit,
        SymfonyStyle $io,
        string $originalInput,
        ?string $dataset,
    ): int {
        $firstChunk = \file_get_contents($input, false, null, 0, 4096) ?: '';
        $delimiter  = \str_contains($firstChunk, "\t") ? "\t" : ',';
        $io->note(\sprintf('Detected CSV delimiter: %s', $delimiter === "\t" ? '\\t (TAB)' : '"," (comma)'));

        $csv = CsvReader::from($input, 'r');
        $csv->setDelimiter($delimiter);
        $csv->setHeaderOffset(0);

        $rawHeader = $csv->getHeader();
        $headerMap = [];

        foreach ($rawHeader as $name) {
            $normalized                             = $this->normalizeHeaderName($name);
            $headerMap[$name]                      = $normalized;
            $this->fieldOriginalNames[$normalized] = $name;
        }

        $this->resetJsonlOutput($output);
        $this->ensureDir($output);
        $writer = JsonlWriter::open($output);

        $count  = 0;
        $index  = 0;
        $format = 'csv';

        foreach ($csv->getRecords() as $record) {
            if (!\is_array($record)) {
                $io->warning('Skipping non-array CSV record.');
                continue;
            }

            $normalizedRow = [];
            foreach ($record as $k => $v) {
                $normKey                 = $headerMap[$k] ?? $k;
                $normalizedRow[$normKey] = $v;
            }

            $normalizedRow = $this->rowNormalizer->normalizeRow($normalizedRow);

            $row = $this->applyRowCallbacks($normalizedRow, $originalInput, $format, $dataset, $index);
            $index++;

            if ($row === null) {
                continue;
            }

            $writer->write($row);
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
        ?string $dataset,
    ): int {
        $contents = \file_get_contents($input);
        if ($contents === false) {
            throw new \RuntimeException(\sprintf('Unable to read JSON file "%s".', $input));
        }

        $decoded = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('JSON root must be an object or array.');
        }

        if ($rootKey !== null) {
            if (!\array_key_exists($rootKey, $decoded)) {
                throw new \RuntimeException(\sprintf(
                    'Root key "%s" not found in JSON. Available keys: %s',
                    $rootKey,
                    \implode(', ', \array_keys($decoded))
                ));
            }
            $items = $decoded[$rootKey];
            if (!\is_array($items)) {
                throw new \RuntimeException(\sprintf('Value at root key "%s" is not an array.', $rootKey));
            }
            $io->note(\sprintf('Using JSON root key "%s" with %d items (if fully loaded).', $rootKey, \count($items)));
        } else {
            $items = $decoded;
            if (!\is_array($items)) {
                throw new \RuntimeException('JSON root must be an array when no rootKey is provided.');
            }
        }

        $this->resetJsonlOutput($output);
        $this->ensureDir($output);
        $writer = JsonlWriter::open($output);

        $count  = 0;
        $index  = 0;
        $format = 'json';

        foreach ($items as $item) {
            if (!\is_array($item)) {
                $io->warning('Skipping non-object item in JSON array.');
                continue;
            }

            $item = $this->rowNormalizer->normalizeRow($item);
            $row  = $this->applyRowCallbacks($item, $originalInput, $format, $dataset, $index);
            $index++;

            if ($row === null) {
                continue;
            }

            $writer->write($row);
            $count++;

            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        $writer->close();
        return $count;
    }

    private function convertJsonRecordsDirToJsonl(
        string $dir,
        string $output,
        ?int $limit,
        SymfonyStyle $io,
        string $originalInput,
        ?string $dataset,
    ): int {
        if (!\is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Records directory "%s" does not exist.', $dir));
        }

        $this->resetJsonlOutput($output);
        $this->ensureDir($output);
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

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $ext  = \strtolower($file->getExtension());
            $path = $file->getPathname();

            if ($ext === 'json') {
                $contents = \file_get_contents($path);
                if ($contents === false) {
                    $io->warning(\sprintf('Unable to read JSON file "%s". Skipping.', $path));
                    continue;
                }

                $decoded = \json_decode($contents, true);
                if (!\is_array($decoded)) {
                    $io->warning(\sprintf('JSON in "%s" is not an object/array. Skipping.', $path));
                    continue;
                }

                if (\array_is_list($decoded)) {
                    foreach ($decoded as $item) {
                        if (!\is_array($item)) {
                            $io->warning(\sprintf('Non-object item in array in "%s". Skipping item.', $path));
                            continue;
                        }

                        $item = $this->rowNormalizer->normalizeRow($item);
                        $row  = $this->applyRowCallbacks($item, $originalInput, 'json', $dataset, $index);
                        $index++;

                        if ($row === null) {
                            continue;
                        }

                        $writer->write($row);
                        $count++;
                        if ($limit !== null && $count >= $limit) {
                            $writer->close();
                            return $count;
                        }
                    }
                } else {
                    $decoded = $this->rowNormalizer->normalizeRow($decoded);
                    $row     = $this->applyRowCallbacks($decoded, $originalInput, 'json', $dataset, $index);
                    $index++;

                    if ($row !== null) {
                        $writer->write($row);
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
                    $io->warning(\sprintf('Unable to open JSONL file "%s". Skipping.', $path));
                    continue;
                }

                while (($line = \fgets($handle)) !== false) {
                    $line = \trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $decoded = \json_decode($line, true);
                    if (\is_array($decoded)) {
                        $decoded = $this->rowNormalizer->normalizeRow($decoded);
                        $row     = $this->applyRowCallbacks($decoded, $originalInput, 'jsonl', $dataset, $index);
                        $index++;

                        if ($row === null) {
                            continue;
                        }

                        $writer->write($row);
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
                $count += $this->appendCsvToJsonl($path, $writer, $limit, $count, $io, $originalInput, $dataset, $index);
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

    private function appendCsvToJsonl(
        string $input,
        JsonlWriter $writer,
        ?int $limit,
        int $currentCount,
        SymfonyStyle $io,
        string $originalInput,
        ?string $dataset,
        int &$index
    ): int {
        $firstChunk = \file_get_contents($input, false, null, 0, 4096) ?: '';
        $delimiter  = \str_contains($firstChunk, "\t") ? "\t" : ',';

        $csv = CsvReader::from($input, 'r');
        $csv->setDelimiter($delimiter);
        $csv->setHeaderOffset(0);

        $rawHeader = $csv->getHeader();
        $headerMap = [];

        foreach ($rawHeader as $name) {
            $normalized                             = $this->normalizeHeaderName($name);
            $headerMap[$name]                      = $normalized;
            $this->fieldOriginalNames[$normalized] = $name;
        }

        $count  = $currentCount;
        $format = 'csv';

        foreach ($csv->getRecords() as $record) {
            if (!\is_array($record)) {
                $io->warning('Skipping non-array CSV record.');
                continue;
            }

            $normalizedRow = [];
            foreach ($record as $k => $v) {
                $normKey                 = $headerMap[$k] ?? $k;
                $normalizedRow[$normKey] = $v;
            }

            $normalizedRow = $this->rowNormalizer->normalizeRow($normalizedRow);

            $row = $this->applyRowCallbacks($normalizedRow, $originalInput, $format, $dataset, $index);
            $index++;

            if ($row === null) {
                continue;
            }

            $writer->write($row);
            $count++;

            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        return $count - $currentCount;
    }

    // ------------------------------------------------------------------
    // Row callbacks & profiling
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $row
     */
    private function applyRowCallbacks(
        array $row,
        string $input,
        string $format,
        ?string $dataset,
        int $index
    ): ?array {
        if ($this->dispatcher === null) {
            return $row;
        }

        $tags = [];
        if ($dataset !== null) {
            $tags[] = $dataset;
        }
        $tags[] = 'format:' . $format;
        $tags[] = 'source:' . \basename($input);
        $tags   = \array_values(\array_unique(\array_merge($tags, $this->extraTags)));

        $event = new ImportConvertRowEvent(
            $row,
            $input,
            $format,
            $index,
            $dataset,
            $tags,
            ImportConvertRowEvent::STATUS_OKAY
        );

        $this->dispatcher->dispatch($event);

        if ($event->row === null) {
            return null;
        }
        if ($event->status !== null && $event->status !== ImportConvertRowEvent::STATUS_OKAY) {
            return null;
        }

        return $event->row;
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

    /**
     * Build field profile + PK candidates + samples from a JSONL file.
     *
     * @return array{
     *   0: array<string,mixed>,
     *   1: int,
     *   2: string[],
     *   3: array{top:array<int,mixed>,bottom:array<int,mixed>}
     * }
     */
    private function buildProfile(string $jsonlPath, ?int $limit): array
    {
        $reader = new JsonlReader($jsonlPath);
        $rows   = [];
        $count  = 0;

        foreach ($reader as $row) {
            $rows[] = $row;
            $count++;
            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        // Field-level stats from JsonlProfiler
        $fieldsProfile = $this->profiler->profile($rows);

        // 🔍 NEW: mark image-like fields (e.g. posterUrl) based on sample rows
        $fieldsProfile = $this->markImageLikeFields($fieldsProfile, $rows);

        // Strict PK-like unique fields (non-null, allowed chars, no duplicates)
        $uniqueFields = $this->detectPrimaryKeyCandidates($fieldsProfile, $count, $rows);

        // Samples: top 1024 rows + bottom 32 rows
        $topLimit    = 1024;
        $bottomLimit = 32;

        $top    = \array_slice($rows, 0, \min($topLimit, $count));
        $bottom = ($count > $bottomLimit)
            ? \array_slice($rows, -$bottomLimit)
            : [];

        $samples = [
            'top'    => $top,
            'bottom' => $bottom,
        ];

        return [$fieldsProfile, $count, $uniqueFields, $samples];
    }

    /**
     * Strict PK candidates:
     *  - field present on every row (total === recordCount, nulls === 0)
     *  - all values non-empty scalars
     *  - no whitespace
     *  - only [A-Za-z0-9_-]
     *  - no duplicates
     *
     * @param array<string,array<string,mixed>> $fieldsProfile
     * @param int                               $recordCount
     * @param array<int,array<string,mixed>>    $rows
     *
     * @return string[]
     */
    private function detectPrimaryKeyCandidates(array $fieldsProfile, int $recordCount, array $rows): array
    {
        if ($recordCount <= 0 || $rows === []) {
            return [];
        }

        $candidates = [];
        foreach ($fieldsProfile as $name => $stats) {
            $total = $stats['total'] ?? null;
            $nulls = $stats['nulls'] ?? null;

            if ($total !== $recordCount || $nulls !== 0) {
                continue;
            }

            $candidates[$name] = [
                'ok'   => true,
                'seen' => [],
            ];
        }

        if ($candidates === []) {
            return [];
        }

        foreach ($rows as $row) {
            foreach ($candidates as $name => &$state) {
                if (!$state['ok']) {
                    continue;
                }

                if (!\array_key_exists($name, $row)) {
                    $state['ok'] = false;
                    continue;
                }

                $value = $row[$name];

                if ($value === null || $value === '') {
                    $state['ok'] = false;
                    continue;
                }

                if (!\is_scalar($value)) {
                    $state['ok'] = false;
                    continue;
                }

                $s = (string) $value;

                if (\preg_match('/\s/', $s) === 1) {
                    $state['ok'] = false;
                    continue;
                }

                if (\preg_match('/[^A-Za-z0-9_-]/', $s) === 1) {
                    $state['ok'] = false;
                    continue;
                }

                if (isset($state['seen'][$s])) {
                    $state['ok'] = false;
                    continue;
                }

                $state['seen'][$s] = true;
            }
            unset($state);
        }

        $result = [];
        foreach ($candidates as $name => $state) {
            if ($state['ok']) {
                $result[] = $name;
            }
        }

        return $result;
    }

    /**
     * Mark fields that look like image URLs, based on the sample rows.
     *
     * We look for string values whose path ends in a common image extension,
     * and if a field has *mostly* such values, we flag it.
     *
     * Adds to each field in $fieldsProfile:
     *   - imageLike: bool
     *   - imageExtensions: string[]
     *
     * @param array<string,array<string,mixed>> $fieldsProfile
     * @param array<int,array<string,mixed>>    $rows
     *
     * @return array<string,array<string,mixed>>
     */
    private function markImageLikeFields(array $fieldsProfile, array $rows): array
    {
        // Extensions we consider "image-ish"
        $imageExtensions = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp',
        ];

        // Per-field counters from sample rows
        $fieldStats = [];

        foreach ($rows as $row) {
            foreach ($row as $name => $value) {
                if (!\is_string($value) || $value === '') {
                    continue;
                }

                // Strip query/fragment so ?w=154 doesn't break extension detection
                $path = \parse_url($value, \PHP_URL_PATH) ?? $value;
                if (!\is_string($path) || $path === '') {
                    continue;
                }

                $ext = \strtolower(\pathinfo($path, \PATHINFO_EXTENSION));
                if ($ext === '') {
                    continue;
                }

                $fieldStats[$name]['total']  = ($fieldStats[$name]['total']  ?? 0) + 1;
                if (\in_array($ext, $imageExtensions, true)) {
                    $fieldStats[$name]['images'] = ($fieldStats[$name]['images'] ?? 0) + 1;
                    $fieldStats[$name]['ext'][$ext] = true;
                }
            }
        }

        foreach ($fieldStats as $name => $stats) {
            $total  = $stats['total']  ?? 0;
            $images = $stats['images'] ?? 0;

            if ($total === 0 || $images === 0) {
                continue;
            }

            $ratio = $images / $total;

            // Heuristic: at least 3 image-y samples and ≥ 50% of samples look like images
            if ($images >= 3 && $ratio >= 0.5) {
                $fieldsProfile[$name]['imageLike']       = true;
                $fieldsProfile[$name]['imageExtensions'] = \array_keys($stats['ext'] ?? []);
            }
        }

        return $fieldsProfile;
    }

}

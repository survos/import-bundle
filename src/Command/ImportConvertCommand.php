<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Command;

use Survos\ImportBundle\Contract\DatasetPathsFactoryInterface;
use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Survos\ImportBundle\Event\ImportConvertStartedEvent;
use Survos\ImportBundle\Service\Provider\ProviderContext;
use Survos\ImportBundle\Service\Provider\RowProviderRegistry;
use Survos\ImportBundle\Service\RowNormalizer;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Service\JsonlProfilerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_values;
use function explode;
use function ltrim;
use function preg_match;
use function realpath;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

#[AsCommand('import:convert', 'Convert CSV/JSON/JSONL to JSONL and generate a profile')]
final class ImportConvertCommand
{
    /** @var array<string,string> normalizedName => originalHeader */
    private array $fieldOriginalNames = [];

    /** @var string[] */
    private array $extraTags = [];

    public function __construct(
        private readonly JsonlProfilerInterface $profiler,
        private readonly RowProviderRegistry $rowProviders,
        private readonly RowNormalizer $rowNormalizer,
        private readonly string $dataDir,
        private readonly ?DatasetPathsFactoryInterface $pathsFactory = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Input CSV/JSON/JSONL path (ZIP/GZ supported). Optional when --dataset is provided.')]
        ?string $input = null,

        #[Option('Override output JSONL path (defaults to <dataDir>/<base>.jsonl, or dataset-aware defaults when --dataset is provided)')]
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

        #[Option(
            'Apply transforms from a prior profile.json (second pass). Pass a profile path explicitly to apply it.'
        )]
        ?string $applyProfile = null,
    ): int {
        $io->title('Import / Convert');

        $this->fieldOriginalNames = [];
        $this->extraTags          = $this->parseExtraTags($tagsOption);

        // Dataset-only invocation:
        //   bin/console import:convert --dataset=fortepan
        // Resolve canonical input/output/profile paths via DatasetPathsFactory (if registered).
        $paths = null;
        if (($input === null || $input === '') && ($dataset !== null && $dataset !== '')) {
            if ($this->pathsFactory === null) {
                $io->error(\sprintf(
                    'Missing <input>. You passed --dataset=%s, but no DatasetPathsFactoryInterface is registered. ' .
                    'Enable museado/data-bundle (or provide your own factory), or pass an explicit input path.',
                    $dataset
                ));
                return Command::FAILURE;
            }

            $paths = $this->pathsFactory->for($dataset);
            $input = $paths->rawObjectPath;

            // Prefer canonical normalized output unless caller provided --output
            $output ??= $paths->normalizedObjectPath;
        }

        if ($input === null || $input === '') {
            $io->error('Missing input. Provide <input> or pass --dataset to infer the canonical raw input path.');
            return Command::FAILURE;
        }

        if (!\is_file($input) && !\is_dir($input)) {
            $io->error(\sprintf('Input file or directory "%s" does not exist.', $input));
            return Command::FAILURE;
        }

        if (($dataset === null || $dataset === '') && $input !== null) {
            $guessed = $this->inferDatasetFromInput($input);
            if ($guessed !== null) {
                $dataset = $guessed;
            }
        }

        // Determine dataset + source
        if (\is_dir($input)) {
            $dataset ??= \basename($input);
            $sourceInput = $input;
            $sourceExt   = 'json_dir';
        } else {
            $ext      = \strtolower(\pathinfo($input, \PATHINFO_EXTENSION));
            $baseName = \pathinfo($input, \PATHINFO_FILENAME);

            $dataset ??= \preg_replace('/\.(jsonl?|csv)$/i', '', $baseName);
            [$sourceInput, $sourceExt] = $this->normalizeInput($input, $ext, $zipPath, $io);
        }

        // If dataset is known and a factory exists, prefer canonical output/profile defaults (unless overridden).
        if ($paths === null && $dataset !== null && $dataset !== '' && $this->pathsFactory !== null) {
            $paths = $this->pathsFactory->for($dataset);
        }

        $jsonlPath = $output ?? (
            $paths ? $paths->normalizedObjectPath : $this->defaultJsonlPath((string) $dataset)
        );

        $profilePath = $paths
            ? $paths->profileObjectPath()
            : $this->defaultProfilePath($jsonlPath);

        // Resolve --apply-profile:
        //  - not provided => no transforms
        //  - provided with no value => use $profilePath
        //  - provided with value => that explicit file path
        $applyProfilePath = null;
        if ($applyProfile !== null) {
            $applyProfilePath = ($applyProfile === '') ? $profilePath : $applyProfile;
        }

        if ($applyProfilePath !== null && !\is_file($applyProfilePath)) {
            $io->warning(\sprintf('Apply-profile file not found: %s (skipping transforms)', $applyProfilePath));
            $applyProfilePath = null;
        }
        if ($applyProfilePath !== null) {
            $io->note(\sprintf('Applying transforms from %s', $applyProfilePath));
        }

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

        // Profile-only mode: treat input as JSONL and skip conversion
        if ($profileOnly) {
            $io->note('Profile-only mode; input is treated as JSONL.');
            $jsonlPath = $sourceInput;
        } else {
            // Keep existing behavior for now; we can switch to JsonlWriterOptions(ensureDir:true) later.
            $this->resetJsonlOutput($jsonlPath);
            $this->ensureDir($jsonlPath);

            $writer = JsonlWriter::open($jsonlPath);

            $io->section(\sprintf('Converting %s to JSONL (from %s)', $sourceExt, $sourceInput));

            $ctx = (new ProviderContext(io: $io, rootKey: $rootKey))
                ->withOnHeader(function (string $normalized, string $original): void {
                    $this->fieldOriginalNames[$normalized] = $original;
                });

            $count = 0;
            $index = 0;

            foreach ($this->rowProviders->iterate($sourceInput, $sourceExt, $ctx) as $row) {
                $row = $this->rowNormalizer->normalizeRow($row);

                $row = $this->applyRowCallbacks(
                    $row,
                    $input,
                    $sourceExt,
                    $dataset,
                    $index,
                    $applyProfilePath
                );
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
            $io->success(\sprintf('Converted %d records to %s', $count, $jsonlPath));
        }

        // Profiling
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
        $uniqueFields = \array_values($uniqueFields);

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

    private function inferDatasetFromInput(string $input): ?string
    {
        $dataRoot = rtrim($this->dataDir, '/');
        $inputReal = realpath($input) ?: $input;

        if (!str_starts_with($inputReal, $dataRoot . '/')) {
            return null;
        }

        $relative = substr($inputReal, strlen($dataRoot) + 1);
        $parts = array_values(array_filter(explode('/', ltrim($relative, '/')), static fn(string $p) => $p !== ''));
        if ($parts === []) {
            return null;
        }

        foreach ($parts as $i => $part) {
            if ($part === 'data' && isset($parts[$i + 1]) && $parts[$i + 1] !== '') {
                return $parts[$i + 1];
            }
        }

        foreach ($parts as $i => $part) {
            if (preg_match('/^\d{2}_[a-z0-9_]+$/i', $part) !== 1) {
                continue;
            }
            if ($i > 0 && $parts[$i - 1] !== '') {
                return $parts[$i - 1];
            }
        }

        return null;
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
        $dir      = \dirname($jsonlPath);
        $filename = \basename($jsonlPath);

        // Strip .gz if present
        if (\str_ends_with($filename, '.gz')) {
            $filename = \substr($filename, 0, -3);
        }

        // Strip one trailing .jsonl or .json
        $filename = \preg_replace('/\.(jsonl|json)$/i', '', $filename, 1);

        return \sprintf('%s/%s.profile.json', $dir, $filename);
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
                    $io->note(\sprintf('ZIP path "%s" is a directory; importing all records under it.', $dirName));
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
                            'Directory "%s" inside ZIP "%s" does not contain JSON/JSONL/CSV files.',
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
                    throw new \RuntimeException(\sprintf('Failed to extract "%s" from ZIP "%s".', $name, $zipPath));
                }
                $zip->close();

                $extractedPath = $tmpDir . '/' . $name;
                $io->note(\sprintf('ZIP extracted via --zip-path: %s (.%s)', $extractedPath, $ext));
                return [$extractedPath, $ext];
            }

            $zip->close();
            throw new \RuntimeException(\sprintf('File or directory "%s" not found inside ZIP "%s".', $filterPath, $zipPath));
        }

        // Heuristic: first CSV/JSON/JSONL file
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
            throw new \RuntimeException(\sprintf('ZIP "%s" does not contain CSV/JSON/JSONL.', $zipPath));
        }

        $io->note(\sprintf('Using "%s" from ZIP (.%s)', $candidateName, $candidateExt));
        $tmpDir = \sys_get_temp_dir() . '/import_bundle_zip_' . \uniqid('', true);
        \mkdir($tmpDir, 0o777, true);
        if (!$zip->extractTo($tmpDir, $candidateName)) {
            $zip->close();
            throw new \RuntimeException(\sprintf('Failed to extract "%s" from ZIP "%s".', $candidateName, $zipPath));
        }
        $zip->close();

        return [$tmpDir . '/' . $candidateName, $candidateExt];
    }

    private function unpackGzipInput(string $gzPath, SymfonyStyle $io): array
    {
        $io->note(\sprintf('GZIP input detected: %s', $gzPath));
        $base     = \pathinfo($gzPath, \PATHINFO_FILENAME);
        $innerExt = \strtolower(\pathinfo($base, \PATHINFO_EXTENSION));

        if ($innerExt === '') {
            throw new \RuntimeException(\sprintf(
                'Cannot infer underlying extension from GZIP "%s". Expected ".csv.gz" or ".json.gz".',
                $gzPath
            ));
        }

        $tmpDir  = \sys_get_temp_dir() . '/import_bundle_gz_' . \uniqid('', true);
        \mkdir($tmpDir, 0o777, true);
        $outPath = $tmpDir . '/' . $base;

        $gz = \gzopen($gzPath, 'rb');
        if ($gz === false) {
            throw new \RuntimeException(\sprintf('Unable to open GZIP "%s".', $gzPath));
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

    /**
     * @param array<string,mixed> $row
     */
    private function applyRowCallbacks(
        array $row,
        string $input,
        string $format,
        ?string $dataset,
        int $index,
        ?string $applyProfilePath = null,
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
            ImportConvertRowEvent::STATUS_OKAY,
            applyProfilePath: $applyProfilePath,
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
        $reader = JsonlReader::open($jsonlPath);
        $rows   = [];
        $count  = 0;

        foreach ($reader as $row) {
            $rows[] = $row;
            $count++;
            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        $fieldsProfile = $this->profiler->profile($rows);
        $uniqueFields  = $this->detectPrimaryKeyCandidates($fieldsProfile, $count, $rows);

        $topLimit    = 1024;
        $bottomLimit = 32;

        $top    = \array_slice($rows, 0, \min($topLimit, $count));
        $bottom = ($count > $bottomLimit) ? \array_slice($rows, -$bottomLimit) : [];

        return [$fieldsProfile, $count, $uniqueFields, ['top' => $top, 'bottom' => $bottom]];
    }

    /**
     * @param array<string,array<string,mixed>> $fieldsProfile
     * @param array<int,array<string,mixed>>    $rows
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

            $candidates[$name] = ['ok' => true, 'seen' => []];
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

                if ($value === null || $value === '' || !\is_scalar($value)) {
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
}

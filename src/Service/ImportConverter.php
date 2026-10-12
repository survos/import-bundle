<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service;

use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Survos\ImportBundle\Event\ImportConvertStartedEvent;
use Survos\ImportBundle\Service\Provider\ProviderContext;
use Survos\ImportBundle\Service\Provider\RowProviderRegistry;
use Survos\ImportBundle\IO\CsvWriter;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Sqlite\SqlProfiler;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

use function array_filter;
use function array_key_exists;
use function array_values;
use function explode;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_file;
use function is_numeric;
use function is_scalar;
use function json_decode;
use function max;
use function preg_match;
use function realpath;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function is_dir;
use function pathinfo;
use function strtolower;
use function preg_replace;
use function trim;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\DataContracts\Vocabulary\MuseumVocab;

/** File conversion with optional caller-owned event context. */
final class ImportConverter
{
    /** @var array<string,string> normalizedName => originalHeader */
    private array $fieldOriginalNames = [];
    private string $currentStage = 'normalize';

    /** @var string[] */
    private array $extraTags = [];

    public function __construct(
        private readonly RowProviderRegistry $rowProviders,
        private readonly RowNormalizer $rowNormalizer,
        private readonly CsvProfileExporter $csvExporter,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?SqlProfiler $sqlProfiler = null,
    ) {
    }

    #[AsCommand('import:convert', 'Convert CSV/JSON/JSONL to JSONL/CSV and generate a profile')]
    public function convert(
        SymfonyStyle $io,

        #[Argument('Input CSV/JSON/JSONL path (ZIP/GZ supported). Required; never inferred from context.')]
        ?string $input = null,

        #[Option('Output JSONL path (defaults to a sibling of the input; required with --dataset context)')]
        ?string $output = null,

        #[Option('Max records to process (for convert/profile)')]
        ?int $limit = null,

        #[Option('Treat input as JSONL and only generate the profile')]
        bool $profileOnly = false,

        #[Option('Deprecated alias for --legacy-profile (writes the legacy .profile.json)')]
        ?bool $saveProfile = null,

        #[Option('Also write the legacy in-memory .profile.json blob (the scalable .profile.db is always written)')]
        bool $legacyProfile = false,

        #[Option('Write the SQL profile sidecar (.db). OFF by default — pass --profile to generate field stats when designing maps; the slow pass is unneeded once maps are settled (zm re-profiles anyway)', name: 'profile')]
        bool $profile = false,

        #[Option('Opaque dataset key for event listeners only; never used to resolve paths')]
        ?string $dataset = null,

        #[Option('Processing stage for row transforms and event listeners', name: 'stage')]
        string $stage = 'normalize',

        #[Option('Core label for conversion progress; never used to resolve paths', name: 'core')]
        ?string $core = null,

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

        #[Option('Output in CSV format instead of JSONL')]
        bool $csv = false,

        #[Option('Dump raw and normalized row N and stop. Use --dump or --dump=1 for the first row.', name: 'dump')]
        bool|int $dump = false,

        #[Option('During --stage=enrich, fold claims from this vault claims.jsonl (canonical one-row-per-claim, from `claims:fetch`/`claims:export`). The caller must supply its path.')]
        ?string $claimsFile = null,
    ): int {
        $io->title('Import / Convert');

        // The scalable SQL sidecar (.profile.db) is the canonical profile now. The
        // legacy in-memory .profile.json (inlines sample rows, OOMs on big cores) is
        // opt-in via --legacy-profile; --save-profile is kept as a deprecated alias.
        $legacyProfile = $legacyProfile || ($saveProfile === true);

        $stage = strtolower(trim($stage));
        if (!in_array($stage, ['raw', 'normalize', 'ai', 'enrich'], true)) {
            $io->error(sprintf('Unsupported --stage=%s. Allowed values: raw, normalize, ai, enrich.', $stage));
            return Command::FAILURE;
        }
        $this->currentStage = $stage;

        $this->fieldOriginalNames = [];
        $this->extraTags          = $this->parseExtraTags($tagsOption);
        $dumpRecord = $this->normalizeDumpRecord($dump, $io);
        if ($dumpRecord === -1) {
            return Command::FAILURE;
        }

        if ($input === null || $input === '') {
            $io->error('Missing input. Pass an explicit input path; dataset/provider path inference is not supported.');
            return Command::FAILURE;
        }
        if ($dataset !== null && $dataset !== '' && ($output === null || $output === '')) {
            $io->error('Pass an explicit output path when providing dataset event context.');
            return Command::FAILURE;
        }

        $input = \Survos\JsonlBundle\Util\Jsonl::resolvePath($input);
        if (!\is_file($input) && !\is_dir($input)) {
            $io->error(\sprintf('Input file or directory "%s" does not exist.', $input));
            return Command::FAILURE;
        }

        if (is_dir($input)) {
            $sourceInput = $input;
            $sourceExt = 'json_dir';
        } else {
            $ext = strtolower(pathinfo($input, PATHINFO_EXTENSION));
            [$sourceInput, $sourceExt] = $this->normalizeInput($input, $ext, $zipPath, $io);
        }

        $outputPath = $output ?? $this->sourceAdjacentJsonlPath($input);

        // Adjust output extension for CSV mode
        if ($csv) {
            $outputPath = $this->adjustExtensionForCsv($outputPath);
        }

        // Never overwrite the source during a bare conversion (e.g. file.jsonl → file.jsonl);
        // resetOutput() would unlink the input before we read it.
        if (!$profileOnly && \is_file($outputPath) && \is_file($sourceInput)
            && \realpath($outputPath) === \realpath($sourceInput)) {
            if ($output !== null) {
                $io->error('Input and output must be different files.');
                return Command::FAILURE;
            }
            $outputPath = preg_replace('/\.(jsonl|csv)(\.gz)?$/i', '.converted.$1$2', $outputPath, 1);
        }

        $profilePath = $this->defaultProfilePath($outputPath);

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

        // Context is supplied by the caller, never inferred from a filename or directory.
        $registryDataset = $dataset ?? '';

        if ($this->dispatcher && $registryDataset !== '') {
            $this->dispatcher->dispatch(
                new ImportConvertStartedEvent(
                    $input,
                    $outputPath,
                    $profilePath,
                    $registryDataset,
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
            $outputPath = $sourceInput;
            $attemptedCount = null;
            $convertedCount = null;
            $rejectedCount = null;
            $limitReached = false;
        } else {
            if ($dumpRecord !== null) {
                $io->section(sprintf('Dumping record %d from %s', $dumpRecord, $sourceInput));
                return $this->dumpRecord(
                    $io,
                    $sourceInput,
                    $sourceExt,
                    (string) $input,
                    $dataset,
                    $rootKey,
                    $applyProfilePath,
                    $dumpRecord
                );
            }

            // Keep existing behavior for now; we can switch to JsonlWriterOptions(ensureDir:true) later.
            $this->resetOutput($outputPath, $sourceInput);
            $this->ensureDir($outputPath);

            $io->section(\sprintf('Converting %s → %s', $sourceExt, $csv ? 'CSV' : 'JSONL'));
            // Show the source + destination up front (repeated in the success footer) so a run is
            // self-documenting — you can see exactly which file fed which without scrolling.
            $io->definitionList(
                ['from' => $sourceInput],
                ['to' => $outputPath],
            );

            if ($csv) {
                $writer = CsvWriter::open($outputPath);
                
                // For CSV input, preserve original header order by writing headers from source
                if ($sourceExt === 'csv') {
                    $writer->writeHeadersFromSourceFile($sourceInput);
                }
            } else {
                $writer = JsonlWriter::open($outputPath);
            }

            $ctx = (new ProviderContext(io: $io, rootKey: $rootKey))
                ->withOnHeader(function (string $normalized, string $original): void {
                    $this->fieldOriginalNames[$normalized] = $original;
                });

            $count = 0;
            $index = 0;
            $attemptedCount = 0;
            $rejectedCount = 0;
            $limitReached = false;
            // Enrich folds claims onto rows from the vault claims.jsonl ONLY (canonical, populated by
            // claims:fetch/claims:export). The legacy 40_ai file is gone — it dropped observation prose
            // and could stale-shadow the vault. No claims file → rows pass through unenriched.
            $claimRows = [];
            if ($stage === 'enrich' && $claimsFile !== null && is_file($claimsFile)) {
                $claimRows = $this->claimRowsFromVault($claimsFile);
            }
            $estimatedRows = $this->estimateSourceRows($sourceInput, (string) $input, $sourceExt);
            $this->startConvertProgress($io, $estimatedRows);

            foreach ($this->rowProviders->iterate($sourceInput, $sourceExt, $ctx) as $row) {
                $attemptedCount++;
                // Long-batch safety valve. The per-row path is pure streaming (read → normalize →
                // dispatch → write, nothing retained), but the heavy per-row churn — reflection,
                // #[Map] DTOs, row events — produces cyclic garbage faster than PHP's default GC
                // threshold reclaims it, so the high-water mark climbs to the memory_limit and OOMs
                // mid-run on million-row datasets (e.g. smith/nmah). It only ever bites at scale, in
                // a background task, where no one is watching. An explicit periodic collection bounds
                // the high-water mark; it's negligible next to the row work.
                if (($attemptedCount % 20000) === 0) {
                    gc_collect_cycles();
                }
                // Pre-step differs by stage: enrich merges any legacy 40_ai claims onto the already-
                // normalized row; normalize maps the raw row. Both then dispatch the row event so the
                // stage's listeners run — crucially, enrich must dispatch too, or the DB-backed
                // AiEnrichRowListener (shared-claims projection) never fires.
                if ($stage === 'enrich') {
                    $row = $this->mergeClaims($row, $claimRows);
                } else {
                    $row = $this->rowNormalizer->normalizeRow($row);
                }

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
                    $rejectedCount++;
                    $this->advanceConvertProgress($io);
                    continue;
                }

                $writer->write($row);
                $count++;
                $this->advanceConvertProgress($io);

                if ($limit !== null && $count >= $limit) {
                    $limitReached = true;
                    break;
                }
            }

            $this->finishConvertProgress($io);

            $convertedCount = $count;

            $writer->close();
            $io->definitionList(
                ['from' => $sourceInput],
                ['to' => $outputPath],
            );
            // Name the core + where it landed so it's clear which stage/core this run wrote — e.g.
            // "100 per records → mus/cleveland/norm/per.jsonl". Relativise off the dataset key (robust
            // against the /platform symlink vs the data dir) so the path stays short.
            $coreName = ($core !== null && $core !== '') ? $core : (string) \preg_replace('/\.jsonl(\.gz)?$/', '', \basename($outputPath));
            $relativeOutput = $outputPath;
            if ($dataset !== null && $dataset !== '' && ($pos = \strpos($outputPath, '/' . $dataset . '/')) !== false) {
                $relativeOutput = \substr($outputPath, $pos + 1);
            }
            $io->success(\sprintf(
                'Converted %d %s records → %s (attempted=%d, rejected=%d%s)',
                $convertedCount,
                $coreName,
                $relativeOutput,
                $attemptedCount,
                $rejectedCount,
                $limitReached ? ', limit reached' : ''
            ));
        }

        $tags = $baseTags;
        $hasExportTag = in_array('export:csv', $tags, true) || in_array('export.csv', $tags, true);

        // Canonical profile: the scalable SQL sidecar (<output>.db). Always written for
        // JSONL output when the profiler is available — it streams the file (no OOM).
        $recordCount = null;
        if ($profile && !$csv && $this->sqlProfiler !== null && \is_file($outputPath)) {
            $io->section('Profiling → SQL sidecar (.profile.db)');
            $t0 = \microtime(true);
            $result = $this->sqlProfiler->profile($outputPath);
            $recordCount = $result->rows;
            $io->success(\sprintf(
                'Profiled %d rows, %d fields → %s.db in %.1fs%s',
                $result->rows,
                $result->fields,
                \basename($outputPath),
                \microtime(true) - $t0,
                $result->invalid > 0 ? \sprintf(' (%d invalid skipped)', $result->invalid) : ''
            ));
        }

        // Legacy in-memory profile.json is gone. It inlined sample rows and OOM'd on big
        // cores; the SQL sidecar (.profile.db) written above is now the canonical profile.
        // If anything still asks for it (--legacy-profile / --save-profile, or CSV output),
        // say so loudly rather than silently regenerating an OOM-prone blob.
        if ($legacyProfile || $csv) {
            $io->warning('Legacy profiler no longer available — the SQL sidecar (.profile.db) is canonical. Read it instead of the legacy profile.json.');
        }

        // Fall back to the converted row count when no profiler ran (CSV-less, profiler off).
        $recordCount ??= $convertedCount ?? 0;

        $dispatchTags = $tags;
        if ($hasExportTag) {
            try {
                $result = $this->csvExporter->exportFromProfile(
                    $profilePath,
                    $outputPath,
                    null,
                    $limit
                );
            } catch (RuntimeException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }

            $io->note(sprintf('CSV export wrote %d rows to %s', $result['recordCount'], $result['outputPath']));

            $dispatchTags = array_values(array_filter(
                $dispatchTags,
                static fn(string $tag): bool => !in_array($tag, ['export:csv', 'export.csv'], true)
            ));
        }

        if ($this->dispatcher && $registryDataset !== '') {
            $this->dispatcher->dispatch(
                new ImportConvertFinishedEvent(
                    $input,
                    $outputPath,
                    $profilePath,
                    $recordCount,
                    $registryDataset,
                    $dispatchTags,
                    $limit,
                    $zipPath,
                    $rootKey
                )
            );
        }

        return Command::SUCCESS;
    }

    private function startConvertProgress(SymfonyStyle $io, ?int $estimatedRows): void
    {
        $format = $estimatedRows === null || $estimatedRows <= 0
            ? ' %current% [%bar%] elapsed:%elapsed:6s% memory:%memory:6s%'
            : ' %current%/%max% [%bar%] %percent:3s%% elapsed:%elapsed:6s% estimated:%estimated:-6s% memory:%memory:6s%';

        $io->progressStart($estimatedRows ?? 0, $format);
    }

    private function advanceConvertProgress(SymfonyStyle $io): void
    {
        $io->progressAdvance();
    }

    private function finishConvertProgress(SymfonyStyle $io): void
    {
        $io->progressFinish();
    }

    private function estimateSourceRows(string $sourceInput, string $input, string $sourceExt): ?int
    {
        $rows = $this->readSidecarRowCount($sourceInput, $sourceExt);
        if ($rows !== null) {
            return $rows;
        }

        if ($input !== $sourceInput) {
            $rows = $this->readSidecarRowCount($input, $sourceExt);
            if ($rows !== null) {
                return $rows;
            }
        }

        return $this->estimateRowsFromSourceFile($sourceInput, $sourceExt);
    }

    private function estimateRowsFromSourceFile(string $path, string $sourceExt): ?int
    {
        if (!in_array($sourceExt, ['csv', 'jsonl'], true) || !is_file($path)) {
            return null;
        }

        if (str_ends_with($path, '.gz')) {
            return $this->estimateGzipRows($path, $sourceExt);
        }

        $lines = $this->countPlainLines($path);
        if ($lines === null) {
            return null;
        }

        return $sourceExt === 'csv' ? max(0, $lines - 1) : $lines;
    }

    private function countPlainLines(string $path): ?int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $lines = 0;
        while (fgets($handle) !== false) {
            $lines++;
        }
        fclose($handle);

        return $lines;
    }

    private function estimateGzipRows(string $path, string $sourceExt): ?int
    {
        // GZIP stores the uncompressed size in the footer; combine it with a small
        // decompressed line sample to avoid a full pre-scan of large .jsonl.gz files.
        $uncompressedSize = $this->gzipUncompressedSize($path);
        if ($uncompressedSize === null || $uncompressedSize <= 0) {
            return null;
        }

        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            return null;
        }

        $lines = 0;
        $bytes = 0;
        while (!gzeof($gz) && $lines < 1000) {
            $line = gzgets($gz);
            if ($line === false) {
                break;
            }

            $lines++;
            $bytes += strlen($line);
        }
        gzclose($gz);

        if ($lines === 0 || $bytes === 0) {
            return null;
        }

        $estimatedLines = (int) max($lines, round($uncompressedSize / ($bytes / $lines)));

        return $sourceExt === 'csv' ? max(0, $estimatedLines - 1) : $estimatedLines;
    }

    private function gzipUncompressedSize(string $path): ?int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false || fseek($handle, -4, SEEK_END) !== 0) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return null;
        }

        $footer = fread($handle, 4);
        fclose($handle);

        if ($footer === false || strlen($footer) !== 4) {
            return null;
        }

        $data = unpack('Vsize', $footer);

        return is_array($data) ? (int) $data['size'] : null;
    }

    private function readSidecarRowCount(string $path, string $sourceExt): ?int
    {
        $sidecarPath = $path . '.sidecar.json';
        if (!is_file($sidecarPath)) {
            return null;
        }

        $json = file_get_contents($sidecarPath);
        if ($json === false || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        foreach ([$decoded, $decoded['metadata'] ?? null, $decoded['sidecar'] ?? null, $decoded['file'] ?? null] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $lineCount = $this->firstPositiveCount($candidate, ['lineCount', 'line_count', 'totalLines', 'total_lines', 'lines']);
            if ($lineCount !== null) {
                return $sourceExt === 'csv' ? max(0, $lineCount - 1) : $lineCount;
            }

            $rowCount = $this->firstPositiveCount($candidate, ['recordCount', 'record_count', 'rows', 'records', 'count', 'total']);
            if ($rowCount !== null) {
                return $rowCount;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private function firstPositiveCount(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || !is_scalar($data[$key]) || !is_numeric($data[$key])) {
                continue;
            }

            $count = (int) $data[$key];
            if ($count >= 0) {
                return $count;
            }
        }

        return null;
    }

    private function normalizeDumpRecord(bool|int $dump, SymfonyStyle $io): ?int
    {
        if ($dump === false) {
            return null;
        }

        if ($dump === true) {
            return 1;
        }

        if ($dump < 1) {
            $io->error('--dump must be a positive integer.');
            return -1;
        }

        return $dump;
    }

    /**
     * Read the vault claims.jsonl (canonical one-row-per-claim, as written by `claims:fetch`)
     * into the same per-subject shape {@see mergeClaims()} consumes. The file is ordered
     * newest-first per subject, so the first value seen per (subjectId, predicate) wins
     * (manifesting — matches ClaimReader::manifestedForSubjects). This is the vault-file path
     * that lets enrich fold claims without touching the live claims DB.
     *
     * @return array<string,list<array{predicate:string,value:mixed}>>
     */
    private function claimRowsFromVault(string $claimsFile): array
    {
        if (!is_file($claimsFile)) {
            return [];
        }

        $claimsById = [];
        $seen = [];
        foreach (JsonlReader::open($claimsFile) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = $row['subjectId'] ?? $row['subject_id'] ?? null;
            $predicate = $row['predicate'] ?? null;
            if (!is_scalar($id) || !is_string($predicate) || !array_key_exists('value', $row)) {
                continue;
            }

            $key = (string) $id . '|' . $predicate;
            if (isset($seen[$key])) {
                continue; // already took the newest value for this subject+predicate
            }
            $seen[$key] = true;

            $claimsById[(string) $id][] = [
                'predicate' => $predicate,
                'value' => $row['value'],
            ];
        }

        return $claimsById;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,list<array{predicate:string,value:mixed}>> $claimRows
     * @return array<string,mixed>
     */
    private function mergeClaims(array $row, array $claimRows): array
    {
        $id = $row[ItemField::ID] ?? $row['id'] ?? null;
        if (!is_scalar($id)) {
            return $row;
        }

        foreach ($claimRows[(string) $id] ?? [] as $claim) {
            $row[$claim['predicate']] = self::decodeClaimValue($claim['value']);
        }

        // Assert AI-derived fields onto canonical DTO slots when the source lacks them, so search +
        // detail treat them as native data (the claims keep provenance separately). We only fill an
        // EMPTY field — a real source title/description is never clobbered.
        $denseSummary = $row[ItemField::DENSE_SUMMARY] ?? null;
        if (self::isBlank($row[ItemField::DESCRIPTION] ?? null) && !self::isBlank($denseSummary)) {
            $row[ItemField::DESCRIPTION] = $denseSummary;
        }
        if (self::isBlank($row[ItemField::TITLE] ?? null) && !self::isBlank($row['ai:caption'] ?? null)) {
            $row[ItemField::TITLE] = $row['ai:caption'];
        }
        // searchSummary is the field the folio FTS always indexes. Seed it from the dense summary
        // (purpose-built "for search and chat") so AI text is findable even though it isn't a
        // declared/searchable source column — without it, searching the summary returns nothing.
        if (self::isBlank($row[ItemField::SEARCH_SUMMARY] ?? null) && !self::isBlank($denseSummary)) {
            $row[ItemField::SEARCH_SUMMARY] = $denseSummary;
        }

        return $row;
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }

    /**
     * Some claim writers store scalar string values double-JSON-encoded (e.g. ai:observationProse as
     * "..\n.." — wrapping quotes + literal backslash-n, not real newlines). Decode that one level so
     * the value is clean prose; leave everything else (already-clean strings, arrays) untouched.
     */
    private static function decodeClaimValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $trimmed = trim($value);
        if (\strlen($trimmed) >= 2 && $trimmed[0] === '"' && str_ends_with($trimmed, '"')) {
            $decoded = json_decode($trimmed, true);
            if (is_string($decoded)) {
                return $decoded;
            }
        }

        return $value;
    }

    private function dumpRecord(
        SymfonyStyle $io,
        string $sourceInput,
        string $sourceExt,
        string $input,
        ?string $dataset,
        ?string $rootKey,
        ?string $applyProfilePath,
        int $dumpRecord,
    ): int {
        $ctx = (new ProviderContext(io: $io, rootKey: $rootKey))
            ->withOnHeader(function (string $normalized, string $original): void {
                $this->fieldOriginalNames[$normalized] = $original;
            });

        $attempted = 0;
        $index = 0;
        foreach ($this->rowProviders->iterate($sourceInput, $sourceExt, $ctx) as $row) {
            $attempted++;
            $rawRow = $this->rowNormalizer->normalizeRow($row);
            $normalizedRow = $this->applyRowCallbacks(
                $rawRow,
                $input,
                $sourceExt,
                $dataset,
                $index,
                $applyProfilePath
            );
            $index++;

            if ($attempted !== $dumpRecord) {
                continue;
            }

            $canonicalFields = $this->canonicalFieldNames();
            $canonical = [];
            $other = [];

            foreach (($normalizedRow ?? []) as $key => $value) {
                if (in_array($key, $canonicalFields, true)) {
                    $canonical[$key] = $value;
                } else {
                    $other[$key] = $value;
                }
            }

            $io->section(sprintf('Raw Row #%d', $dumpRecord));
            $io->writeln(json_encode($rawRow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $io->section(sprintf('Normalized Row #%d', $dumpRecord));
            $io->writeln(json_encode($normalizedRow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $io->section('Canonical Fields');
            $io->writeln(json_encode($canonical, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $io->section('Other Fields');
            $io->writeln(json_encode($other, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io->warning(sprintf('Record %d not found; attempted %d row(s).', $dumpRecord, $attempted));
        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function canonicalFieldNames(): array
    {
        return [
            ItemField::SOURCE_ID,
            ItemField::ID,
            ItemField::TITLE,
            ItemField::DESCRIPTION,
            'type',
            ItemField::RIGHTS,
            'citation',
            MuseumVocab::CULTURE,
            MuseumVocab::TECHNIQUE,
            MuseumVocab::DEPARTMENT,
            MuseumVocab::COLLECTION,
            ItemField::CITATION_URL,
            ItemField::URL,
            ItemField::PAGE_URL,
            ItemField::IIIF_BASE,
            ItemField::THUMBNAIL_URL,
            ItemField::LICENSE,
        ];
    }


    /**
     * Output path for a bare file conversion (no --output): a .jsonl sibling
     * of the source, so we never relocate the user's file under APP_DATA_DIR.
     */
    private function sourceAdjacentJsonlPath(string $input): string
    {
        $dir      = \dirname($input);
        $filename = \basename($input);

        if (\str_ends_with(\strtolower($filename), '.gz')) {
            $filename = \substr($filename, 0, -3);
        }
        $filename = \preg_replace('/\.(jsonl|json|csv)$/i', '', $filename, 1);

        return \sprintf('%s/%s.jsonl', $dir, $filename);
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
            (new Filesystem())->mkdir($dir);
        }
    }

    private function resetOutput(string $output, ?string $source = null): void
    {
        if (\is_file($output)) {
            \unlink($output);
        }
        // Drop the other encoding too: readers prefer a plain file over its .gz, so a stale
        // twin would shadow (or be shadowed by) what this run writes.
        if (\preg_match('/\.jsonl(\.gz)?$/', $output)) {
            $twin = \str_ends_with($output, '.gz') ? \substr($output, 0, -3) : $output.'.gz';
            $isSource = $source !== null && \is_file($twin) && \is_file($source) && \realpath($twin) === \realpath($source);
            foreach ($isSource ? [] : [$twin, $twin.'.db'] as $stale) {
                if (\is_file($stale)) {
                    \unlink($stale);
                }
            }
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

    private function adjustExtensionForCsv(string $path): string
    {
        // Handle .gz compression
        $isCompressed = str_ends_with($path, '.gz');
        if ($isCompressed) {
            $path = substr($path, 0, -3);
        }

        // Replace .jsonl or .json with .csv
        if (str_ends_with($path, '.jsonl')) {
            $path = substr($path, 0, -6) . '.csv';
        } elseif (str_ends_with($path, '.json')) {
            $path = substr($path, 0, -5) . '.csv';
        } elseif (!str_ends_with($path, '.csv')) {
            $path .= '.csv';
        }

        // Add back .gz if it was compressed
        if ($isCompressed) {
            $path .= '.gz';
        }

        return $path;
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
                    if (!\mkdir($tmpDir, 0o777, true) && !\is_dir($tmpDir)) {
                        throw new \RuntimeException(sprintf('Cannot create temp directory "%s".', $tmpDir));
                    }
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
                'Cannot infer underlying extension from GZIP "%s". Expected ".csv.gz", ".json.gz", or ".jsonl.gz".',
                $gzPath
            ));
        }

        if ($innerExt === 'jsonl') {
            return [$gzPath, $innerExt];
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
            $dataset ?? '',
            $tags,
            ImportConvertRowEvent::STATUS_OKAY,
            stage: $this->currentStage ?? 'normalize',
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

}

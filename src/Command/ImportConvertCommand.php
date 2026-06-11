<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\ImportBundle\Contract\DatasetPathsFactoryInterface;
use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Survos\ImportBundle\Event\ImportConvertStartedEvent;
use Survos\ImportBundle\Service\CsvProfileExporter;
use Survos\ImportBundle\Service\Provider\ProviderContext;
use Survos\ImportBundle\Service\Provider\RowProviderRegistry;
use Survos\ImportBundle\Service\RowNormalizer;
use League\Csv\Reader as CsvReader;
use Survos\ImportBundle\IO\CsvWriter;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Service\JsonlProfilerInterface;
use Survos\JsonlBundle\Sqlite\SqlProfiler;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
use function ltrim;
use function max;
use function preg_match;
use function realpath;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Entity\Provider;
use Survos\DatasetBundle\Enum\Stage;
use Survos\DataContracts\Metadata\ContentType;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\DataContracts\Vocabulary\MuseumVocab;

/**
 * Conversion service AND the import:convert command.
 *
 * The #[AsCommand] is on convert() (method-level) rather than the class, so this is a
 * plain autowired service that other bundles (e.g. dataset-bundle's dataset:normalize /
 * dataset:assemble shortcuts) can inject and call convert() directly — passing the same
 * SymfonyStyle — while `import:convert` keeps working unchanged. This is the first step
 * toward imports being initiated from dataset-bundle (import soft-depends on dataset).
 */
final class ImportConvertCommand
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
        private readonly string $dataDir,
        private readonly ?DatasetPathsFactoryInterface $pathsFactory = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?JsonlProfilerInterface $profiler = null,
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly ?SqlProfiler $sqlProfiler = null,
        private readonly ?UrlGeneratorInterface $urlGenerator = null,
    ) {
    }

    #[AsCommand('import:convert', 'Convert CSV/JSON/JSONL to JSONL/CSV and generate a profile')]
    public function convert(
        SymfonyStyle $io,

        #[Argument('Input CSV/JSON/JSONL path (ZIP/GZ supported). Optional when --dataset is provided.')]
        ?string $input = null,

        #[Option('Override output JSONL path (defaults to <dataDir>/<base>.jsonl, or dataset-aware defaults when --dataset is provided)')]
        ?string $output = null,

        #[Option('Max records to process (for convert/profile)')]
        ?int $limit = null,

        #[Option('Treat input as JSONL and only generate the profile')]
        bool $profileOnly = false,

        #[Option('Deprecated alias for --legacy-profile (writes the legacy .profile.json)')]
        ?bool $saveProfile = null,

        #[Option('Also write the legacy in-memory .profile.json blob (the scalable .profile.db is always written)')]
        bool $legacyProfile = false,

        #[Option('Dataset code (e.g. "wam", "marvel")')]
        ?string $dataset = null,

        #[Option('Dataset stage to write/read canonically: raw or normalize', name: 'stage')]
        string $stage = 'normalize',

        #[Option('Dataset core filename stem (default: obj)', name: 'core')]
        string $core = 'obj',

        #[Option('Convert every raw core JSONL file for the dataset')]
        bool $allCores = false,

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

        #[Option('Convert all datasets for a provider (e.g. "mus")')]
        ?string $provider = null,

        #[Option('Output in CSV format instead of JSONL')]
        bool $csv = false,

        #[Option('Dump raw and normalized row N and stop. Use --dump or --dump=1 for the first row.', name: 'dump')]
        bool|int $dump = false,
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

        $core = trim($core);
        if ($core === '') {
            $io->error('The --core option cannot be empty.');
            return Command::FAILURE;
        }

        // Resolve dataset list: --provider populates from DB, --dataset is a single entry
        $datasetKeys = [];
        if ($provider !== null && $provider !== '') {
            if ($this->entityManager === null) {
                $io->error('--provider requires dataset-bundle.');
                return Command::FAILURE;
            }
            $providerEntity = $this->entityManager->getRepository(Provider::class)->find($provider);
            if ($providerEntity === null) {
                $io->error(sprintf('Provider "%s" not found. Run dataset:scan first.', $provider));
                return Command::FAILURE;
            }
            $datasetKeys = array_map(static fn(DatasetInfo $d): string => $d->datasetKey, $providerEntity->getDatasets()->toArray());
        } elseif ($dataset !== null && $dataset !== '' && $input === null) {
            $datasetKeys = [$dataset];
        }

        // Multiple datasets: iterate and recurse
        if (count($datasetKeys) > 1) {
            $failed = 0;
            foreach ($datasetKeys as $key) {
                $io->section($key);
                $result = $this->convert(
                    $io,
                    dataset: $key,
                    limit: $limit,
                    stage: $stage,
                    core: $core,
                    allCores: $allCores,
                    legacyProfile: $legacyProfile,
                    csv: $csv
                );
                if ($result !== Command::SUCCESS) {
                    $failed++;
                }
            }
            return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        // Single dataset from list
        if (count($datasetKeys) === 1) {
            $dataset = $datasetKeys[0];
        }

        $this->fieldOriginalNames = [];
        $this->extraTags          = $this->parseExtraTags($tagsOption);
        $dumpRecord = $this->normalizeDumpRecord($dump, $io);
        if ($dumpRecord === -1) {
            return Command::FAILURE;
        }

        // Dataset-only invocation:
        //   bin/console import:convert --dataset=fortepan
        // Resolve canonical input/output/profile paths via DatasetPathsFactory (if registered).
        $paths = null;
        if (($input === null || $input === '') && ($dataset !== null && $dataset !== '')) {
            if ($this->pathsFactory === null) {
                $io->error(\sprintf(
                    'Missing <input>. You passed --dataset=%s, but no DatasetPathsFactoryInterface is registered. ' .
                    'Enable survos/data-bundle (or provide your own factory), or pass an explicit input path.',
                    $dataset
                ));
                return Command::FAILURE;
            }

            $paths = $this->pathsFactory->for($dataset);

            if ($allCores) {
                if ($output !== null && $output !== '') {
                    $io->error('--all-cores cannot be combined with --output. Each core writes to its canonical output path.');
                    return Command::FAILURE;
                }
                if ($profileOnly) {
                    $io->error('--all-cores cannot be combined with --profile-only. Profile one core at a time.');
                    return Command::FAILURE;
                }

                $cores = $this->discoverRawCores($paths);
                if ($cores === []) {
                    $io->error(sprintf('No raw core JSONL files found in %s.', $paths->rawDir));
                    return Command::FAILURE;
                }

                $failed = 0;
                foreach ($cores as $rawCore) {
                    $io->section(sprintf('%s / %s', $dataset, $rawCore));
                    $result = $this(
                        $io,
                        limit: $limit,
                        legacyProfile: $legacyProfile,
                        dataset: $dataset,
                        stage: $stage,
                        core: $rawCore,
                        tagsOption: $tagsOption,
                        zipPath: $zipPath,
                        rootKey: $rootKey,
                        applyProfile: $applyProfile,
                        csv: $csv,
                        dump: $dump
                    );
                    if ($result !== Command::SUCCESS) {
                        $failed++;
                    }
                }

                return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
            }

            $inputStage = $profileOnly ? $stage : self::inputStage($stage);
            $input = $this->canonicalStagePath($paths, $inputStage, $core);

            // Prefer canonical stage output unless caller provided --output
            $output ??= $this->canonicalStagePath($paths, $stage, $core);
        }

        if ($input === null || $input === '') {
            $io->error('Missing input. Provide <input> or pass --dataset to infer the canonical raw input path.');
            return Command::FAILURE;
        }

        if (!\is_file($input) && !\is_dir($input)) {
            $io->error(\sprintf('Input file or directory "%s" does not exist.', $input));
            return Command::FAILURE;
        }

        // A "real" dataset is one the caller named (--dataset/--provider) or one we can
        // infer because the input lives under APP_DATA_DIR. Only real datasets route their
        // output/profile to canonical APP_DATA_DIR paths and update the dataset registry.
        // A bare file elsewhere must NOT be relocated under APP_DATA_DIR (see #obj/obj bug).
        $datasetExplicit = $dataset !== null && $dataset !== '';
        $datasetInferredFromPath = false;
        if (!$datasetExplicit && $input !== null) {
            $guessed = $this->inferDatasetFromInput($input);
            if ($guessed !== null) {
                $dataset = $guessed;
                $datasetInferredFromPath = true;
            }
        }
        $realDataset = $datasetExplicit || $datasetInferredFromPath;

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

        // If this is a real dataset and a factory exists, prefer canonical output/profile
        // defaults — but only when the caller has NOT provided an explicit --output, so we
        // don't route a simple file conversion to APP_DATA_DIR. A bare file with no --dataset
        // stays next to its source (see sourceAdjacentJsonlPath).
        if ($paths === null && $output === null && $realDataset && $dataset !== '' && $this->pathsFactory !== null) {
            $paths = $this->pathsFactory->for($dataset);
        }

        $outputPath = $output ?? match (true) {
            $paths !== null => $this->canonicalStagePath($paths, $stage, $core),
            $realDataset    => $this->defaultJsonlPath((string) $dataset),
            default         => $this->sourceAdjacentJsonlPath((string) $input),
        };

        // Adjust output extension for CSV mode
        if ($csv) {
            $outputPath = $this->adjustExtensionForCsv($outputPath);
        }

        // Never overwrite the source during a bare conversion (e.g. file.jsonl → file.jsonl);
        // resetOutput() would unlink the input before we read it.
        if (!$profileOnly && \is_file($outputPath) && \is_file($sourceInput)
            && \realpath($outputPath) === \realpath($sourceInput)) {
            $outputPath = \preg_replace('/\.(jsonl|csv)$/i', '.converted.$1', $outputPath, 1);
        }

        $profilePath = $paths
            ? $this->canonicalProfilePath($paths, $stage, $core)
            : $this->defaultProfilePath($outputPath);

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

        // Only a real dataset is reported to the registry / gets a browse link; a bare
        // file conversion passes '' so the registry listener skips it.
        $registryDataset = $realDataset ? (string) $dataset : '';

        // Dataset-lifecycle events are only meaningful for a real dataset; a bare file
        // conversion skips them (listeners build DataPaths from the dataset key).
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
            $this->resetOutput($outputPath);
            $this->ensureDir($outputPath);

            $io->section(\sprintf('Converting %s to %s (from %s)', $sourceExt, $csv ? 'CSV' : 'JSONL', $sourceInput));

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
            $claimRows = $stage === 'enrich' && $paths !== null ? $this->claimRows($paths, $core) : [];
            $estimatedRows = $this->estimateSourceRows($sourceInput, (string) $input, $sourceExt);
            $this->startConvertProgress($io, $estimatedRows);

            foreach ($this->rowProviders->iterate($sourceInput, $sourceExt, $ctx) as $row) {
                $attemptedCount++;
                if ($stage === 'enrich') {
                    $row = $this->mergeClaims($row, $claimRows);
                } else {
                    $row = $this->rowNormalizer->normalizeRow($row);

                    $row = $this->applyRowCallbacks(
                        $row,
                        $input,
                        $sourceExt,
                        $dataset,
                        $index,
                        $applyProfilePath
                    );
                }
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
            $io->success(\sprintf(
                'Converted %d records to %s (attempted=%d, rejected=%d%s)',
                $convertedCount,
                $outputPath,
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
        if (!$csv && $this->sqlProfiler !== null && \is_file($outputPath)) {
            $io->section('Profiling → SQL sidecar (.profile.db)');
            $result = $this->sqlProfiler->profile($outputPath);
            $recordCount = $result->rows;
            $io->success(\sprintf(
                'Profiled %d rows, %d fields → %s.db%s',
                $result->rows,
                $result->fields,
                \basename($outputPath),
                $result->invalid > 0 ? \sprintf(' (%d invalid skipped)', $result->invalid) : ''
            ));
        }

        // Legacy in-memory profile.json — opt-in, but still required for CSV profiling
        // and the export:csv tag (which reads samples/fields back from the json blob).
        $writeLegacy = $legacyProfile || $csv || $hasExportTag;
        if ($writeLegacy) {
            $io->section(\sprintf('Legacy profiling %s', $csv ? 'CSV' : 'JSONL'));
            [$fieldsProfile, $recordCount, $uniqueFields, $samples, $extraFieldStats] = $this->buildProfile($outputPath, $limit);

            // Inject original header name if we know it
            foreach ($fieldsProfile as $name => &$stats) {
                if (isset($this->fieldOriginalNames[$name])) {
                    $stats['originalName'] = $this->fieldOriginalNames[$name];
                }
            }
            unset($stats);

            $uniqueFields = \array_values($uniqueFields);

            if ($uniqueFields) {
                $io->note('PK-like unique fields: ' . \implode(', ', $uniqueFields));
            } elseif ($recordCount > 0) {
                // Only warn about missing PK when there are actually records to index.
                // 0-record collections are expected (e.g. all items filtered by rights).
                $io->warning('No PK-like unique field detected (non-null, allowed chars, no duplicates).');
                $io->writeln('  → You may need to fix the profile logic or provide a separate id field.');
            }

            $fullProfile = [
                'input'        => $input,
                'output'       => $outputPath,
                'recordCount'  => $recordCount,
                'requestedLimit' => $limit,
                'attemptedCount' => $attemptedCount,
                'convertedCount' => $convertedCount ?? null,
                'rejectedCount' => $rejectedCount,
                'limitReached' => $limitReached,
                'tags'         => $tags,
                'dataset'      => $registryDataset,
                'uniqueFields' => $uniqueFields,
                'fields'       => $fieldsProfile,
                'samples'      => $samples,
                'extraFields'  => $extraFieldStats,
            ];

            $this->ensureDir($profilePath);
            \file_put_contents(
                $profilePath,
                \json_encode($fullProfile, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)
            );
            $io->success(\sprintf('Legacy profile written to %s', $profilePath));
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

        // Hand the user a link to browse the dataset they just loaded.
        if ($registryDataset !== '') {
            $browseUrl = $this->browseUrl($registryDataset);
            if ($browseUrl !== null) {
                $io->writeln('');
                $io->writeln(\sprintf('  Browse: <href=%s>%s</>', $browseUrl, $browseUrl));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Build an absolute URL to browse a dataset (provider/code) in the UI, or null when
     * no router is wired or the route is unavailable (import-bundle is UI-agnostic).
     */
    private function browseUrl(string $datasetKey): ?string
    {
        if ($this->urlGenerator === null) {
            return null;
        }

        [$provider, $code] = \array_pad(\explode('/', $datasetKey, 2), 2, null);
        if ($provider === null || $code === null || $code === '') {
            return null;
        }

        try {
            return $this->urlGenerator->generate(
                'data_bundle_dataset_show',
                ['provider' => $provider, 'code' => $code],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        } catch (\Throwable) {
            return null;
        }
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
            return $this->readSidecarRowCount($input, $sourceExt);
        }

        return null;
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
     * @return array<string,list<array{predicate:string,value:mixed}>>
     */
    private function claimRows(\Survos\ImportBundle\Model\DatasetPaths $paths, string $core): array
    {
        $file = rtrim($paths->datasetRoot, '/') . '/40_ai/' . $core . '.jsonl';
        if (!is_file($file)) {
            return [];
        }

        $claimsById = [];
        foreach (JsonlReader::open($file) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $claims = $row['claims'] ?? null;
            if (!is_scalar($id) || !is_array($claims)) {
                continue;
            }

            foreach ($claims as $claim) {
                if (!is_array($claim) || !is_string($claim['predicate'] ?? null) || !array_key_exists('value', $claim)) {
                    continue;
                }

                $claimsById[(string) $id][] = [
                    'predicate' => $claim['predicate'],
                    'value' => $claim['value'],
                ];
            }
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
            $row[$claim['predicate']] = $claim['value'];
        }

        return $row;
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

    /** Returns the canonical input stage for a given output stage (for conversion, not profiling). */
    public static function inputStage(string $stage): string
    {
        return in_array($stage, ['ai', 'enrich'], true) ? 'normalize' : 'raw';
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
     * @return list<string>
     */
    private function discoverRawCores(\Survos\ImportBundle\Model\DatasetPaths $paths): array
    {
        $rawDir = rtrim($paths->rawDir, '/');
        if (!is_dir($rawDir)) {
            return [];
        }

        $cores = [];
        foreach (new \DirectoryIterator($rawDir) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $name = $file->getFilename();
            if (preg_match('/^([A-Za-z0-9_-]+)\.jsonl(?:\.gz)?$/', $name, $matches) !== 1) {
                continue;
            }

            $cores[$matches[1]] = true;
        }

        $result = array_keys($cores);
        usort($result, static function (string $left, string $right): int {
            if ($left === 'obj') {
                return $right === 'obj' ? 0 : -1;
            }
            if ($right === 'obj') {
                return 1;
            }

            return $left <=> $right;
        });

        return array_values($result);
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

    private function canonicalStagePath(\Survos\ImportBundle\Model\DatasetPaths $paths, string $stage, string $core): string
    {
        return match ($stage) {
            'raw'   => $this->canonicalRawInputPath($paths, $core),
            // TODO(bundle-refactor): ai claims belong in the vault (DataPaths::aiDir), not work.
            'ai'     => rtrim($paths->datasetRoot, '/') . '/ai/' . $core . '.jsonl',
            'enrich' => rtrim($paths->datasetRoot, '/') . '/' . Stage::Enrich->dir() . '/' . $core . '.jsonl',
            default => rtrim($paths->normalizedDir, '/') . '/' . $core . '.jsonl',
        };
    }

    private function canonicalRawInputPath(\Survos\ImportBundle\Model\DatasetPaths $paths, string $core): string
    {
        if ($core === 'obj') {
            if (is_file($paths->rawObjectPath)) {
                return $paths->rawObjectPath;
            }

            if (!str_ends_with($paths->rawObjectPath, '.gz') && is_file($paths->rawObjectPath . '.gz')) {
                return $paths->rawObjectPath . '.gz';
            }
        }

        $base = rtrim($paths->rawDir, '/') . '/' . $core . '.jsonl';
        if (is_file($base)) {
            return $base;
        }

        $gz = $base . '.gz';
        if (is_file($gz)) {
            return $gz;
        }

        return $base;
    }

    private function canonicalProfilePath(\Survos\ImportBundle\Model\DatasetPaths $paths, string $stage, string $core): string
    {
        $basePath = $this->canonicalStagePath($paths, $stage, $core);
        $dir = ($stage === 'raw') ? $paths->rawDir : ($paths->profileDir ?? dirname($basePath));
        $base = basename($basePath);
        $base = preg_replace('/\.jsonl$/i', '', $base, 1);

        return sprintf('%s/%s.profile.json', $dir, $base);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function defaultJsonlPath(string $baseName): string
    {
        $dir = \rtrim($this->dataDir, '/');
        return \sprintf('%s/%s.jsonl', $dir, $baseName);
    }

    /**
     * Output path for a bare file conversion (no --dataset, no --output): a .jsonl sibling
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

    private function resetOutput(string $output): void
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
            $dataset,
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

    /**
     * Build field profile + PK candidates + samples from a JSONL or CSV file.
     *
     * @return array{
     *   0: array<string,mixed>,
     *   1: int,
     *   2: string[],
     *   3: array{top:array<int,mixed>,bottom:array<int,mixed>},
     *   4: array<string,mixed>
     * }
     */
    private function buildProfile(string $filePath, ?int $limit): array
    {
        $rows  = [];
        $count = 0;

        // Determine file type and read accordingly
        if (str_ends_with($filePath, '.csv')) {
            if (!\class_exists(CsvReader::class)) {
                throw new RuntimeException('CSV profiling requires league/csv. Install it with: composer require league/csv');
            }

            $csv = CsvReader::from($filePath);
            $csv->setHeaderOffset(0);
            foreach ($csv->getRecords() as $record) {
                if (!\is_array($record)) {
                    continue;
                }
                $rows[] = $record;
                $count++;
                if ($limit !== null && $count >= $limit) {
                    break;
                }
            }
        } else {
            // JSONL
            $reader = JsonlReader::open($filePath);
            foreach ($reader as $row) {
                $rows[] = $row;
                $count++;
                if ($limit !== null && $count >= $limit) {
                    break;
                }
            }
        }

        if ($this->profiler === null) {
            $io->note('Profiling skipped — install survos/jsonl-bundle to enable field profiling.');
            return [[], $count, [], ['top' => [], 'bottom' => []], $this->emptyExtraFieldStats()];
        }
        $fieldsProfile = $this->profiler->profile($rows);
        $uniqueFields  = $this->detectPrimaryKeyCandidates($fieldsProfile, $count, $rows);
        $extraFieldStats = $this->profileExtraFields($rows);

        $topLimit    = 1024;
        $bottomLimit = 32;

        $top    = \array_slice($rows, 0, \min($topLimit, $count));
        $bottom = ($count > $bottomLimit) ? \array_slice($rows, -$bottomLimit) : [];

        return [$fieldsProfile, $count, $uniqueFields, ['top' => $top, 'bottom' => $bottom], $extraFieldStats];
    }


    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function profileExtraFields(array $rows): array
    {
        $stats = $this->emptyExtraFieldStats();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $stats['rows']++;
            $contentType = $this->rowContentType($row);
            $stats['byContentType'][$contentType] ??= [
                'rows' => 0,
                'rowsWithExtras' => 0,
                'rowsWithExtrasPercent' => 0.0,
                'distinctKeyCount' => 0,
                'keys' => [],
            ];
            $stats['byContentType'][$contentType]['rows']++;

            $extras = $this->extraFieldsForRow($row);
            if ($extras === []) {
                continue;
            }

            $stats['rowsWithExtras']++;
            $stats['maxExtraFieldsPerRow'] = max($stats['maxExtraFieldsPerRow'], count($extras));
            $stats['byContentType'][$contentType]['rowsWithExtras']++;

            foreach (array_keys($extras) as $key) {
                $stats['keys'][$key] = ($stats['keys'][$key] ?? 0) + 1;
                $stats['byContentType'][$contentType]['keys'][$key] = ($stats['byContentType'][$contentType]['keys'][$key] ?? 0) + 1;
            }
        }

        arsort($stats['keys']);
        $stats['distinctKeyCount'] = count($stats['keys']);
        $stats['rowsWithExtrasPercent'] = $stats['rows'] > 0 ? round(($stats['rowsWithExtras'] / $stats['rows']) * 100, 2) : 0.0;

        foreach ($stats['byContentType'] as $type => $typeStats) {
            arsort($typeStats['keys']);
            $typeStats['distinctKeyCount'] = count($typeStats['keys']);
            $typeStats['rowsWithExtrasPercent'] = $typeStats['rows'] > 0 ? round(($typeStats['rowsWithExtras'] / $typeStats['rows']) * 100, 2) : 0.0;
            $stats['byContentType'][$type] = $typeStats;
        }

        return $stats;
    }

    /** @return array{rows:int,rowsWithExtras:int,rowsWithExtrasPercent:float,distinctKeyCount:int,maxExtraFieldsPerRow:int,keys:array<string,int>,byContentType:array<string,mixed>} */
    private function emptyExtraFieldStats(): array
    {
        return [
            'rows' => 0,
            'rowsWithExtras' => 0,
            'rowsWithExtrasPercent' => 0.0,
            'distinctKeyCount' => 0,
            'maxExtraFieldsPerRow' => 0,
            'keys' => [],
            'byContentType' => [],
        ];
    }

    /** @param array<string,mixed> $row */
    private function rowContentType(array $row): string
    {
        $type = $row['contentType'] ?? $row['content_type'] ?? null;

        return is_scalar($type) && trim((string) $type) !== '' ? trim((string) $type) : '(missing)';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function extraFieldsForRow(array $row): array
    {
        $type = $this->rowContentType($row);
        if ($type === '(missing)') {
            return $row;
        }

        $class = ContentType::dtoClass($type);
        if (!class_exists($class)) {
            return $row;
        }

        try {
            $dto = method_exists($class, 'fromNormalized') ? $class::fromNormalized($row) : new $class();
        } catch (\TypeError) {
            $dto = new $class();
        }
        $known = array_fill_keys(array_keys(get_object_vars($dto)), true);
        foreach ($this->knownNormalizedFieldNames() as $fieldName) {
            $known[$fieldName] = true;
        }
        foreach (['class', 'content_type', 'contentType', 'dto_type', 'dtoType'] as $alias) {
            $known[$alias] = true;
        }

        $extras = [];
        foreach ($row as $key => $value) {
            if (!isset($known[$key])) {
                $extras[$key] = $value;
            }
        }

        return $extras;
    }

    /** @return list<string> */
    private function knownNormalizedFieldNames(): array
    {
        static $fields = null;
        if ($fields !== null) {
            return $fields;
        }

        $fieldMap = [];
        foreach ([ItemField::class, MuseumVocab::class] as $interface) {
            foreach ((new \ReflectionClass($interface))->getConstants() as $value) {
                if (is_string($value) && $value !== '') {
                    $fieldMap[$value] = true;
                }
            }
        }

        $fields = array_keys($fieldMap);

        return $fields;
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

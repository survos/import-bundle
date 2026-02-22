<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Command;

use DOMDocument;
use DOMXPath;
use DirectoryIterator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\String\Slugger\SluggerInterface;
use ZipArchive;

use function json_encode;
use function sprintf;
use function array_map;
use function explode;
use function implode;
use function in_array;
use function pathinfo;
use function basename;
use function dirname;
use function is_dir;
use function shell_exec;
use function escapeshellarg;
use function str_starts_with;
use function is_string;
use function is_numeric;
use function function_exists;
use function finfo_open;
use function finfo_file;
use function finfo_close;
use function hash_algos;
use function hash_init;
use function hash_update_stream;
use function hash_final;
use function hash;
use function sha1;
use function json_decode;
use function is_array;
use function count;
use function min;
use function array_filter;
use function array_values;
use function strtolower;
use function trim;
use function rtrim;
use function realpath;
use function strlen;
use function substr;
use function ltrim;

#[AsCommand('import:filesystem', 'Scan local filesystem directories and output JSONL for processing')]
final class ImportFilesystemCommand
{
    public function __construct(
        private readonly SluggerInterface $slugger,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Directory path to scan')]
        string $directory,

        #[Option('Output JSONL file path')]
        ?string $output = null,

        #[Option('File extensions to include (comma-separated)')]
        ?string $extensions = null,

        #[Option('File extensions to exclude (comma-separated)')]
        ?string $excludeExtensions = null,

        #[Option('Always emit a directory row for every unique parent dir, even when filtered by extension')]
        ?bool $tree = null,

        #[Option('Directories to exclude (comma-separated)')]
        ?string $excludeDirs = null,

        #[Option('Probe level: 0=fast path, 1=stat/mime/checksum, 2=deep (ffprobe/docx), 3=audio fingerprint (chromaprint)')]
        int $probe = 0,

        #[Option('Audio fingerprint similarity threshold (0.0 - 1.0)')]
        float $audioSimilarity = 0.90,

        #[Option('Progress mode: buckets (top-level ETA), indeterminate (no pre-scan), none')]
        string $progressMode = 'buckets',

        #[Option('Include dotfiles and dot directories')]
        bool $includeHidden = false,

        #[Option('Root id for this scan (used by file proxy)')]
        ?string $rootId = null,

        #[Option('Root path override for this scan')]
        ?string $rootPath = null,
    ): int {

        $tree ??= true;
        if ($probe < 0 || $probe > 3) {
            $io->error('Probe level must be 0, 1, 2, or 3.');
            return Command::FAILURE;
        }

        if ($audioSimilarity < 0.0 || $audioSimilarity > 1.0) {
            $io->error('Audio similarity threshold must be between 0.0 and 1.0.');
            return Command::FAILURE;
        }

        $progressMode = strtolower(trim($progressMode));
        if (!in_array($progressMode, ['buckets', 'indeterminate', 'none'], true)) {
            $io->error('Progress mode must be buckets, indeterminate, or none.');
            return Command::FAILURE;
        }

        if (!is_dir($directory)) {
            $io->error(sprintf('Directory not found: %s', $directory));
            return Command::FAILURE;
        }

        $rootId ??= basename(rtrim($directory, '/'));
        $rootPath ??= $directory;
        $rootPathReal = realpath($rootPath) ?: $rootPath;

        // Parse extensions include filter
        $allowedExtensions = [];
        if ($extensions !== null) {
            $allowedExtensions = array_map(
                static fn(string $ext): string => strtolower(trim($ext)),
                explode(',', $extensions)
            );
        }

        // Parse extensions exclusion filter
        $excludedExtensions = [];
        if ($excludeExtensions !== null) {
            $excludedExtensions = array_map(
                static fn(string $ext): string => strtolower(trim($ext)),
                explode(',', $excludeExtensions)
            );
        }

        // Parse excluded directories
        $excludedDirectories = [];
        if ($excludeDirs !== null) {
            $excludedDirectories = array_map(
                static fn(string $dir): string => trim($dir),
                explode(',', $excludeDirs)
            );
        }

        $io->title(sprintf('Scanning directory: %s', $directory));
        if (!empty($allowedExtensions)) {
            $io->note(sprintf('Filtering extensions: %s', implode(', ', $allowedExtensions)));
        }
        if (!empty($excludedExtensions)) {
            $io->note(sprintf('Excluding extensions: %s', implode(', ', $excludedExtensions)));
        }
        if (!empty($excludedDirectories)) {
            $io->note(sprintf('Excluding directories: %s', implode(', ', $excludedDirectories)));
        }
        if ($output === null) {
            $output = basename(rtrim($directory, '/')) . '.jsonl';
        }

        $io->note(sprintf('Output: %s', $output));
        $io->note(sprintf('Probe level: %d', $probe));
        if ($probe >= 3) {
            $io->note(sprintf('Audio similarity threshold: %.2f', $audioSimilarity));
        }

        $xxh3Available = in_array('xxh3', hash_algos(), true);
        if ($probe >= 1 && !$xxh3Available) {
            $io->warning('xxh3 is not available; checksum_xxh3 will be omitted.');
        }

        $finder = new Finder();
        $idx = 0;
        $writer = JsonlWriter::open($output);
        // special record for root?
//        $writer->write(['id' => $rootId, 'type' => 'root', 'path' => $rootPath, 'relative_path' => '']);

        foreach ($finder->in($directory) as $realPath => $file) {
            dd($file);
            $id = hash('xxh3', $file->getRelativePathname());
            if ($file->isDir()) {

            }
            $io->writeln(sprintf('Found %s: %s', $file->isDir() ? 'dir' : 'file', $file->getRelativePathname()));
            ($idx++ > 10) && dd();
        }


        $buckets = [];
        if ($progressMode === 'buckets') {
            $topLevelDirectories = [];
            foreach (new DirectoryIterator($directory) as $entry) {
                if ($entry->isDot() || !$entry->isDir()) {
                    continue;
                }

                $name = $entry->getFilename();
                if (!$includeHidden && str_starts_with($name, '.')) {
                    continue;
                }
                $path = $entry->getPathname();
                if (in_array($name, $excludedDirectories, true) || in_array($path, $excludedDirectories, true)) {
                    continue;
                }

                $topLevelDirectories[] = $path;
            }

            $rootFinder = new Finder();
            $rootFinder->files()->in($directory)->depth('== 0');
            $rootFinder->ignoreDotFiles(!$includeHidden);
            if (!empty($allowedExtensions)) {
                $rootFinder->name(sprintf('/\.(%s)$/i', implode('|', $allowedExtensions)));
            }
            if (!empty($excludedExtensions)) {
                $rootFinder->notName(sprintf('/\.(%s)$/i', implode('|', $excludedExtensions)));
            }
            $buckets[] = ['label' => $directory, 'finder' => $rootFinder];

            foreach ($topLevelDirectories as $topLevelDirectory) {
                $bucketFinder = new Finder();
                $bucketFinder->files()->in($topLevelDirectory);
                $bucketFinder->ignoreDotFiles(!$includeHidden);
                foreach ($excludedDirectories as $excludeDir) {
                    $bucketFinder->notPath($excludeDir);
                }
                if (!empty($allowedExtensions)) {
                    $bucketFinder->name(sprintf('/\.(%s)$/i', implode('|', $allowedExtensions)));
                }
                if (!empty($excludedExtensions)) {
                    $bucketFinder->notName(sprintf('/\.(%s)$/i', implode('|', $excludedExtensions)));
                }
                $buckets[] = ['label' => $topLevelDirectory, 'finder' => $bucketFinder];
            }
        } else {
            $finder = new Finder();
            $finder->files()->in($directory);
            $finder->ignoreDotFiles(!$includeHidden);
            foreach ($excludedDirectories as $excludeDir) {
                $finder->notPath($excludeDir);
            }
            if (!empty($allowedExtensions)) {
                $finder->name(sprintf('/\.(%s)$/i', implode('|', $allowedExtensions)));
            }
            if (!empty($excludedExtensions)) {
                $finder->notName(sprintf('/\.(%s)$/i', implode('|', $excludedExtensions)));
            }
            $buckets[] = ['label' => $directory, 'finder' => $finder];
        }

        $count = 0;
        $totalSize = 0;

        $seenChecksums = [];
        $audioFingerprints = [];
        $stopwatch = new Stopwatch();
        $fpcalcAvailable = true;
        $overallEventName = 'import_filesystem_total';
        $stopwatch->start($overallEventName);

        $progressBar = null;
        if ($progressMode === 'buckets') {
            $progressBar = $io->createProgressBar(count($buckets));
            $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %estimated:-6s% %message%');
            $progressBar->setMessage('Preparing buckets...');
            $progressBar->start();
        } elseif ($progressMode === 'indeterminate') {
            $progressBar = $io->createProgressBar();
            $progressBar->setMaxSteps(0);
            $progressBar->setFormat('%current% [%bar%] %elapsed:6s% %message%');
            $progressBar->setMessage('Scanning files...');
            $progressBar->start();
        }

        $progressTick = 0;

        foreach ($buckets as $bucket) {
            /** @var Finder $finder */
            $finder = $bucket['finder'];

            foreach ($finder as $file) {
                $filepath = $file->getPathname();
                $relativePath = $this->relativeToRoot($filepath, $rootPathReal, $file->getRelativePathname());

            $id = $xxh3Available ? hash('xxh3', $relativePath) : sha1($relativePath);

            $tags = $this->parentDirTags($relativePath);
            $sluggedTags = array_map($this->slugify(...), $tags);
            $sluggedRootId = $this->slugify($rootId);

            // Pre-compute deterministic instance IDs so importers can build
            // the tree without DB round-trips or flush ordering concerns.
            $instanceSlug = $sluggedRootId . ($sluggedTags !== [] ? '/' . implode('/', $sluggedTags) : '');
            $instanceId   = hash('xxh3', $instanceSlug);

            $parentSluggedTags  = array_slice($sluggedTags, 0, -1);
            $parentInstanceSlug = $sluggedRootId . ($parentSluggedTags !== [] ? '/' . implode('/', $parentSluggedTags) : '');
            $parentInstanceId   = $sluggedTags !== [] ? hash('xxh3', $parentInstanceSlug) : null;

            $metadata = [
                'id' => $id,
                'root_id' => $rootId,
                'root_path' => $rootPath,
                'path' => $filepath,
                'relative_path' => $relativePath,
                'filename' => basename($filepath),
                'basename' => basename($filepath),
                'dirname' => dirname($filepath),
                'extension' => strtolower(pathinfo($filepath, PATHINFO_EXTENSION)),
                'tags' => $tags,
                'instance_slug' => $instanceSlug,
                'instance_id' => $instanceId,
                'parent_instance_slug' => $parentInstanceSlug,
                'parent_instance_id' => $parentInstanceId,
                'probe_level' => $probe,
            ];

            $isReadable = $file->isReadable();
            if (!$isReadable) {
                $io->warning(sprintf('Unreadable file: %s', $filepath));
            }

            if ($probe >= 1) {
                $size = $file->getSize();
                $metadata['size'] = $size;
                $totalSize += $size;
                if ($isReadable) {
                    $mimeType = $this->detectMimeType($filepath);
                    if ($mimeType !== null) {
                        $metadata['mime_type'] = $mimeType;
                    }
                }
                $metadata['created_time'] = $file->getCTime();
                $metadata['modified_time'] = $file->getMTime();
                $metadata['is_readable'] = $isReadable;

                if ($xxh3Available && $isReadable) {
                    $checksum = $this->computeXxh3Checksum($filepath);
                    if ($checksum !== null) {
                        $metadata['checksum_xxh3'] = $checksum;

                        if (isset($seenChecksums[$checksum])) {
                            $metadata['duplicate_of'] = $seenChecksums[$checksum];
                        } else {
                            $seenChecksums[$checksum] = $filepath;
                        }
                    }
                }
            }

            if ($probe >= 2 && $isReadable) {
                $metadata += $this->probeDeep($filepath, $metadata['extension'] ?? null, $metadata['mime_type'] ?? null);
            }

            if ($probe >= 3 && $isReadable && $this->shouldProbeMedia($metadata['extension'] ?? null, $metadata['mime_type'] ?? null)) {
                if ($fpcalcAvailable && !$this->isFpcalcAvailable()) {
                    $fpcalcAvailable = false;
                    $io->warning('fpcalc (Chromaprint) is not available; audio fingerprints will be omitted.');
                }

                if ($fpcalcAvailable) {
                    $fingerprint = $this->probeAudioFingerprint($filepath, $stopwatch, $io, (int) $metadata['size']);
                    if ($fingerprint !== null) {
                        $metadata += $fingerprint['metadata'];

                        $fingerprintHash = $fingerprint['hash'];
                        $fingerprintValues = $fingerprint['values'];

                        if (isset($audioFingerprints[$fingerprintHash])) {
                            $metadata['audio_duplicate_of'] = $audioFingerprints[$fingerprintHash]['path'];
                        } else {
                            foreach ($audioFingerprints as $existing) {
                                $similarity = $this->compareFingerprintSimilarity($fingerprintValues, $existing['values']);
                                if ($similarity >= $audioSimilarity) {
                                    $metadata['audio_similar_to'] = $existing['path'];
                                    $metadata['audio_similarity'] = $similarity;
                                    break;
                                }
                            }

                            $audioFingerprints[$fingerprintHash] = [
                                'path' => $filepath,
                                'values' => $fingerprintValues,
                            ];
                        }
                    }
                }
            }

            $writer->write($metadata);

                $count++;
                if ($progressMode === 'indeterminate' && $progressBar !== null) {
                    $progressTick++;
                    if ($progressTick % 1000 === 0) {
                        $progressBar->setMessage(sprintf('Processed %d files', $count));
                        $progressBar->advance(1000);
                    }
                }
            }

            if ($progressMode === 'buckets' && $progressBar !== null) {
                $progressBar->setMessage(sprintf('Processed bucket: %s (%d files so far)', $bucket['label'], $count));
                $progressBar->advance();
            }
        }

        if ($progressMode === 'indeterminate' && $progressBar !== null && $progressTick % 1000 !== 0) {
            $progressBar->setMessage(sprintf('Processed %d files', $count));
            $progressBar->advance($progressTick % 1000);
        }

        if ($progressBar !== null) {
            $progressBar->finish();
            $io->newLine();
        }

        // ── Tree pass: emit a directory row for every unique subdirectory ──
        if ($tree) {
            $sluggedRootId = $this->slugify($rootId);
            $seenDirs = [];
            $dirFinder = new Finder();
            $dirFinder->directories()->in($directory);
            $dirFinder->ignoreDotFiles(!$includeHidden);
            foreach ($excludedDirectories as $excludeDir) {
                $dirFinder->notPath($excludeDir);
            }
            foreach ($dirFinder as $dir) {
                $relativePath = $this->relativeToRoot($dir->getPathname(), $rootPathReal, $dir->getRelativePathname());
                if (isset($seenDirs[$relativePath])) {
                    continue;
                }
                $seenDirs[$relativePath] = true;

                $tags        = array_values(array_filter(explode('/', $relativePath), static fn(string $p) => $p !== ''));
                $sluggedTags = array_map($this->slugify(...), $tags);

                $instanceSlug = $sluggedRootId . '/' . implode('/', $sluggedTags);
                $instanceId   = hash('xxh3', $instanceSlug);

                $parentSluggedTags  = array_slice($sluggedTags, 0, -1);
                $parentInstanceSlug = $sluggedRootId . ($parentSluggedTags !== [] ? '/' . implode('/', $parentSluggedTags) : '');
                $parentInstanceId   = hash('xxh3', $parentInstanceSlug);

                $writer->write([
                    'id'                  => hash('xxh3', $relativePath),
                    'type'                => 'directory',
                    'root_id'             => $rootId,
                    'root_path'           => $rootPath,
                    'path'                => $dir->getPathname(),
                    'relative_path'       => $relativePath,
                    'tags'                => $tags,
                    'instance_slug'       => $instanceSlug,
                    'instance_id'         => $instanceId,
                    'parent_instance_slug' => $parentInstanceSlug,
                    'parent_instance_id'  => $parentInstanceId,
                ], $instanceId);
            }
        }

        $overallEvent = $stopwatch->stop($overallEventName);
        $seconds = $overallEvent->getDuration() / 1000;
        $filesPerSecond = $seconds > 0.0 ? $count / $seconds : 0.0;
        $mbPerSecond = $seconds > 0.0 ? ($totalSize / 1024 / 1024) / $seconds : 0.0;
        $writer->finish();

        $io->section('Summary');
        $io->text(sprintf('Files: %d', $count));
        $io->text(sprintf('Output: %s', $output));
        $io->text(sprintf('Elapsed: %.3fs', $seconds));
        $io->text(sprintf('Rate: %.2f files/s', $filesPerSecond));
        if ($probe >= 1) {
            $io->text(sprintf('Throughput: %.2f MB/s', $mbPerSecond));
        }

        $io->success(sprintf('Successfully scanned %d files to %s', $count, $output));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function probeDeep(string $filepath, ?string $extension, ?string $mimeType): array
    {
        $metadata = [];

        if ($this->shouldProbeMedia($extension, $mimeType)) {
            $duration = $this->probeFfprobeDuration($filepath);
            if ($duration !== null) {
                $metadata['media_duration'] = $duration;
            }
        }

        if ($extension !== null && in_array($extension, ['docx'], true)) {
            $metadata += $this->probeDocxMetadata($filepath);
        }

        return $metadata;
    }

    private function shouldProbeMedia(?string $extension, ?string $mimeType): bool
    {
        if ($mimeType !== null && (str_starts_with($mimeType, 'audio/') || str_starts_with($mimeType, 'video/'))) {
            return true;
        }

        if ($extension === null) {
            return false;
        }

        return in_array($extension, ['mp3', 'wav', 'aac', 'flac', 'mp4', 'm4a', 'mov', 'mkv', 'webm'], true);
    }

    private function probeFfprobeDuration(string $filepath): ?float
    {
        $which = shell_exec('command -v ffprobe 2>/dev/null');
        if (!is_string($which) || trim($which) === '') {
            return null;
        }

        $command = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellarg($filepath)
        );
        $output = shell_exec($command);
        if (!is_string($output)) {
            return null;
        }

        $output = trim($output);
        if ($output === '' || !is_numeric($output)) {
            return null;
        }

        return (float) $output;
    }

    /**
     * @return array<string, string>
     */
    private function probeDocxMetadata(string $filepath): array
    {
        $metadata = [];
        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            return $metadata;
        }

        $coreXml = $zip->getFromName('docProps/core.xml');
        $zip->close();

        if ($coreXml === false) {
            return $metadata;
        }

        $doc = new DOMDocument();
        if (!$doc->loadXML($coreXml)) {
            return $metadata;
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $xpath->registerNamespace('dcterms', 'http://purl.org/dc/terms/');
        $xpath->registerNamespace('cp', 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties');

        $author = trim($xpath->evaluate('string(//dc:creator)'));
        if ($author !== '') {
            $metadata['docx_author'] = $author;
        }

        $title = trim($xpath->evaluate('string(//dc:title)'));
        if ($title !== '') {
            $metadata['docx_title'] = $title;
        }

        $subject = trim($xpath->evaluate('string(//dc:subject)'));
        if ($subject !== '') {
            $metadata['docx_subject'] = $subject;
        }

        $keywords = trim($xpath->evaluate('string(//cp:keywords)'));
        if ($keywords !== '') {
            $metadata['docx_keywords'] = $keywords;
        }

        $created = trim($xpath->evaluate('string(//dcterms:created)'));
        if ($created !== '') {
            $metadata['docx_created_time'] = $created;
        }

        $modified = trim($xpath->evaluate('string(//dcterms:modified)'));
        if ($modified !== '') {
            $metadata['docx_modified_time'] = $modified;
        }

        return $metadata;
    }

    private function detectMimeType(string $filepath): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mimeType = finfo_file($finfo, $filepath);
        finfo_close($finfo);

        if (!is_string($mimeType) || trim($mimeType) === '') {
            return null;
        }

        return $mimeType;
    }

    private function computeXxh3Checksum(string $filepath): ?string
    {
        $hash = hash_init('xxh3');
        $fh = fopen($filepath, 'rb');
        if ($fh === false) {
            return null;
        }

        $updated = hash_update_stream($hash, $fh);
        fclose($fh);

        if ($updated === false) {
            return null;
        }

        return hash_final($hash);
    }

    private function isFpcalcAvailable(): bool
    {
        $which = shell_exec('command -v fpcalc 2>/dev/null');
        return is_string($which) && trim($which) !== '';
    }

    /**
     * @return array{metadata: array<string, mixed>, hash: string, values: array<int, int>}|null
     */
    private function probeAudioFingerprint(string $filepath, Stopwatch $stopwatch, SymfonyStyle $io, int $size): ?array
    {
        $eventName = 'fpcalc_' . $filepath;
        $stopwatch->start($eventName);

        $command = sprintf('fpcalc -json %s 2>/dev/null', escapeshellarg($filepath));
        $output = shell_exec($command);

        $event = $stopwatch->stop($eventName);
        $seconds = $event->getDuration() / 1000;
        $rate = $seconds > 0.0 ? ($size / 1024 / 1024) / $seconds : 0.0;

        $io->text(sprintf(
            'fpcalc %s | %d bytes | %.3fs | %.2f MB/s',
            $filepath,
            $size,
            $seconds,
            $rate
        ));

        if (!is_string($output) || trim($output) === '') {
            return null;
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded) || !isset($decoded['fingerprint']) || !is_array($decoded['fingerprint'])) {
            return null;
        }

        $values = [];
        foreach ($decoded['fingerprint'] as $value) {
            if (!is_numeric($value)) {
                return null;
            }
            $values[] = (int) $value;
        }

        if ($values === []) {
            return null;
        }

        $fingerprintHash = hash('sha1', implode(',', $values));
        $metadata = [
            'audio_fingerprint' => $values,
            'audio_fingerprint_hash' => $fingerprintHash,
        ];

        if (isset($decoded['duration']) && is_numeric($decoded['duration'])) {
            $metadata['audio_fingerprint_duration'] = (float) $decoded['duration'];
        }

        return [
            'metadata' => $metadata,
            'hash' => $fingerprintHash,
            'values' => $values,
        ];
    }

    /**
     * @param array<int, int> $a
     * @param array<int, int> $b
     */
    private function compareFingerprintSimilarity(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $matches = 0;
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] === $b[$i]) {
                $matches++;
            }
        }

        return $matches / $len;
    }

    /** @return string[] */
    private function parentDirTags(string $relativePath): array
    {
        $relativeDir = dirname($relativePath);
        if ($relativeDir === '.' || $relativeDir === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $relativeDir), static fn(string $p) => $p !== ''));
    }

    private function slugify(string $value): string
    {
        return strtolower($this->slugger->slug($value)->toString());
    }

    private function relativeToRoot(string $filePath, string $rootPath, string $fallback): string
    {
        $fileReal = realpath($filePath) ?: $filePath;
        $rootReal = rtrim($rootPath, '/');

        if ($rootReal !== '' && str_starts_with($fileReal, $rootReal)) {
            $relative = substr($fileReal, strlen($rootReal));
            return ltrim($relative, '/');
        }

        return $fallback;
    }
}

<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Command;

use Survos\ImportBundle\Event\ImportDirDirectoryEvent;
use Survos\ImportBundle\Event\ImportDirFileEvent;
use Survos\ImportBundle\Model\Directory;
use Survos\ImportBundle\Model\File;
use Survos\ImportBundle\Model\FinderFileInfo;
use Survos\ImportBundle\Service\ProbeService;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function array_filter;
use function array_values;
use function basename;
use function count;
use function dirname;
use function explode;
use function hash;
use function hash_algos;
use function in_array;
use function is_dir;
use function is_readable;
use function implode;
use function ltrim;
use function pathinfo;
use function realpath;
use function rtrim;
use function sha1;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

#[AsCommand('import:dir', 'Scan a directory and emit DIR/FILE DTO rows as JSONL')]
final class ImportDirCommand
{
    public function __construct(
        private readonly ProbeService $probeService,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

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
        #[Option('Directories to exclude (comma-separated)')]
        ?string $excludeDirs = null,
        #[Option('Probe level: 0=fast path, 1=stat/mime/checksum, 2=deep (ffprobe/docx), 3=audio fingerprint (chromaprint)')]
        int $probe = 0,
        #[Option('Audio fingerprint similarity threshold (0.0 - 1.0)')]
        float $audioSimilarity = 0.90,
        #[Option('Include dotfiles and dot directories')]
        bool $includeHidden = false,
        #[Option('Root id for this scan')]
        ?string $rootId = null,
        #[Option('Root path override for this scan')]
        ?string $rootPath = null,
        #[Option('Show determinate progress (pre-count rows first)')]
        ?bool $showCount = null,
    ): int {
        if ($probe < 0 || $probe > 3) {
            $io->error('Probe level must be 0, 1, 2, or 3.');
            return Command::FAILURE;
        }

        if ($audioSimilarity < 0.0 || $audioSimilarity > 1.0) {
            $io->error('Audio similarity threshold must be between 0.0 and 1.0.');
            return Command::FAILURE;
        }

        if (!is_dir($directory)) {
            $io->error(sprintf('Directory not found: %s', $directory));
            return Command::FAILURE;
        }

        $allowedExtensions = $this->parseCsvList($extensions, lowercase: true);
        $excludedExtensions = $this->parseCsvList($excludeExtensions, lowercase: true);
        $excludedDirectories = $this->parseCsvList($excludeDirs, lowercase: false);

        $rootId ??= basename(rtrim($directory, '/'));
        $rootPath ??= $directory;
        $rootPathReal = realpath($rootPath) ?: $rootPath;

        if ($output === null) {
            $output = basename(rtrim($directory, '/')) . '.jsonl';
        }

        $io->title(sprintf('Scanning directory: %s', $directory));
        $io->note(sprintf('Output: %s', $output));
        $io->note(sprintf('Probe level: %d', $probe));
        $io->note(sprintf('DIR rows always emitted: %s', 'yes'));

        if ($allowedExtensions !== []) {
            $io->note(sprintf('Filtering extensions: %s', implode(', ', $allowedExtensions)));
        }
        if ($excludedExtensions !== []) {
            $io->note(sprintf('Excluding extensions: %s', implode(', ', $excludedExtensions)));
        }
        if ($excludedDirectories !== []) {
            $io->note(sprintf('Excluding directories: %s', implode(', ', $excludedDirectories)));
        }

        if ($probe >= 1 && !in_array('xxh3', hash_algos(), true)) {
            $io->warning('xxh3 is not available; ids/checksums will fall back to sha1 where needed.');
        }

        $writer = JsonlWriter::open($output);
        $this->probeService->reset();

        $progressBar = $this->createProgressBar(
            io: $io,
            directory: $directory,
            includeHidden: $includeHidden,
            excludedDirectories: $excludedDirectories,
            allowedExtensions: $allowedExtensions,
            excludedExtensions: $excludedExtensions,
            showCount: $showCount,
        );

        $rowCount = 0;
        $dirCount = 0;
        $fileCount = 0;
        $totalSize = 0;

        /** @var array<string, string> $seenDirIds relativePath => id */
        $seenDirIds = [];

        $rootDirInfo = new FinderFileInfo(
            pathname: $rootPathReal,
            relativePathname: '',
            relativePath: '',
            filename: basename(rtrim($rootPathReal, '/')),
            basename: basename(rtrim($rootPathReal, '/')),
            dirname: dirname($rootPathReal),
            extension: '',
            isReadable: is_readable($rootPathReal),
            isDir: true,
        );

        $rootDirectory = new Directory(
            id: $this->idForDir(''),
            parentId: null,
            rootId: $rootId,
            relativePath: '',
            fileInfo: $rootDirInfo,
        );

        $this->dispatchDirectoryEvent($rootDirectory, $probe);
        $writer->write($rootDirectory->toArray());
        $seenDirIds[''] = $rootDirectory->id;
        $rowCount++;
        $dirCount++;
        $this->advanceProgress($progressBar, $rowCount);

        $finder = $this->createFinder($directory, $includeHidden, $excludedDirectories);

        $emitDir = function (string $relativePath, ?FinderFileInfo $fileInfo = null) use (
            &$emitDir,
            &$seenDirIds,
            $rootId,
            $rootPathReal,
            $probe,
            $writer,
            &$rowCount,
            &$dirCount,
            $progressBar
        ): string {
            if (isset($seenDirIds[$relativePath])) {
                return $seenDirIds[$relativePath];
            }

            $parentRelative = $this->parentRelativePath($relativePath);
            $parentId = $emitDir($parentRelative);

            $absolutePath = $relativePath === ''
                ? $rootPathReal
                : rtrim($rootPathReal, '/') . '/' . $relativePath;

            $info = $fileInfo ?? new FinderFileInfo(
                pathname: $absolutePath,
                relativePathname: $relativePath,
                relativePath: $parentRelative,
                filename: basename($absolutePath),
                basename: basename($absolutePath),
                dirname: dirname($absolutePath),
                extension: '',
                isReadable: is_readable($absolutePath),
                isDir: true,
            );

            $directoryDto = new Directory(
                id: $this->idForDir($relativePath),
                parentId: $parentId,
                rootId: $rootId,
                relativePath: $relativePath,
                fileInfo: $info,
            );
            $directoryDto->tags = $this->pathTags($relativePath);

            $this->dispatchDirectoryEvent($directoryDto, $probe);
            $writer->write($directoryDto->toArray());

            $seenDirIds[$relativePath] = $directoryDto->id;
            $rowCount++;
            $dirCount++;
            $this->advanceProgress($progressBar, $rowCount);

            return $directoryDto->id;
        };

        foreach ($finder as $entry) {
            $relativePath = $this->relativeToRoot($entry->getPathname(), $rootPathReal, $entry->getRelativePathname());
            $parentRelative = $this->parentRelativePath($relativePath);

            $fileInfo = FinderFileInfo::fromSplFileInfo($entry, $relativePath);

            if ($entry->isDir()) {
                if ($relativePath === '') {
                    continue;
                }

                $emitDir($relativePath, $fileInfo);
                continue;
            }

            if (!$this->shouldIncludeFile($fileInfo->extension, $allowedExtensions, $excludedExtensions)) {
                continue;
            }

            $parentId = $emitDir($parentRelative);

            $fileDto = new File(
                id: $this->idForFile($relativePath),
                parentId: $parentId,
                rootId: $rootId,
                relativePath: $relativePath,
                probeLevel: $probe,
                fileInfo: $fileInfo,
            );
            $fileDto->tags = $this->pathTags($parentRelative);
            $fileDto->metadata['root_path'] = $rootPath;

            $this->dispatchFileEvent($fileDto, $probe, $audioSimilarity);

            $writer->write($fileDto->toArray());
            $rowCount++;
            $fileCount++;
            $totalSize += (int) ($fileInfo->size ?? 0);
            $this->advanceProgress($progressBar, $rowCount);
        }

        if ($progressBar !== null) {
            $progressBar->finish();
            $io->newLine();
        }

        $writer->finish();

        $io->section('Summary');
        $io->text(sprintf('Rows: %d', $rowCount));
        $io->text(sprintf('Directories: %d', $dirCount));
        $io->text(sprintf('Files: %d', $fileCount));
        if ($probe >= 1) {
            $io->text(sprintf('Total bytes: %d', $totalSize));
        }
        $io->text(sprintf('Output: %s', $output));
        $io->success(sprintf('Successfully scanned %s', $directory));

        return Command::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function parseCsvList(?string $value, bool $lowercase): array
    {
        if ($value === null) {
            return [];
        }

        $parts = array_values(array_filter(array_map(
            static fn(string $part): string => trim($part),
            explode(',', $value)
        )));

        if ($lowercase) {
            $parts = array_map(static fn(string $part): string => strtolower($part), $parts);
        }

        return $parts;
    }

    /**
     * @param string[] $excludedDirectories
     */
    private function createFinder(string $directory, bool $includeHidden, array $excludedDirectories): Finder
    {
        $finder = new Finder();
        $finder->in($directory);
        $finder->ignoreDotFiles(!$includeHidden);
        $finder->ignoreVCS(true);
        $finder->sortByName();

        foreach ($excludedDirectories as $excludeDir) {
            $finder->notPath($excludeDir);
        }

        return $finder;
    }

    /**
     * @param string[] $allowedExtensions
     * @param string[] $excludedExtensions
     */
    private function shouldIncludeFile(string $extension, array $allowedExtensions, array $excludedExtensions): bool
    {
        if ($allowedExtensions !== [] && !in_array($extension, $allowedExtensions, true)) {
            return false;
        }

        return !in_array($extension, $excludedExtensions, true);
    }

    private function parentRelativePath(string $relativePath): string
    {
        if ($relativePath === '') {
            return '';
        }

        $parent = dirname($relativePath);
        if ($parent === '.' || $parent === '/') {
            return '';
        }

        return $parent;
    }

    /** @return string[] */
    private function pathTags(string $relativePath): array
    {
        if ($relativePath === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $relativePath), static fn(string $part): bool => $part !== ''));
    }

    private function idForDir(string $relativePath): string
    {
        return $this->hashId('DIR:' . $relativePath);
    }

    private function idForFile(string $relativePath): string
    {
        return $this->hashId('FILE:' . $relativePath);
    }

    private function hashId(string $value): string
    {
        if (in_array('xxh3', hash_algos(), true)) {
            return hash('xxh3', $value);
        }

        return sha1($value);
    }

    private function relativeToRoot(string $filePath, string $rootPath, string $fallback): string
    {
        $fileReal = realpath($filePath) ?: $filePath;
        $rootReal = rtrim($rootPath, '/');

        if ($rootReal !== '' && str_contains($fileReal, $rootReal) && str_starts_with($fileReal, $rootReal)) {
            $relative = substr($fileReal, strlen($rootReal));
            return ltrim($relative, '/');
        }

        return $fallback;
    }

    /**
     * @param string[] $excludedDirectories
     * @param string[] $allowedExtensions
     * @param string[] $excludedExtensions
     */
    private function createProgressBar(
        SymfonyStyle $io,
        string $directory,
        bool $includeHidden,
        array $excludedDirectories,
        array $allowedExtensions,
        array $excludedExtensions,
        ?bool $showCount,
    ): ?ProgressBar {
        if ($showCount !== true) {
            $bar = $io->createProgressBar();
            $bar->setMaxSteps(0);
            $bar->setFormat('%current% [%bar%] %elapsed:6s% %message%');
            $bar->setMessage('Scanning (indeterminate)...');
            $bar->start();

            return $bar;
        }

        $estimatedRows = $this->countRows(
            directory: $directory,
            includeHidden: $includeHidden,
            excludedDirectories: $excludedDirectories,
            allowedExtensions: $allowedExtensions,
            excludedExtensions: $excludedExtensions,
        );

        $bar = $io->createProgressBar($estimatedRows);
        $bar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %estimated:-6s% %message%');
        $bar->setMessage('Scanning...');
        $bar->start();

        return $bar;
    }

    private function advanceProgress(?ProgressBar $progressBar, int $rows): void
    {
        if ($progressBar === null) {
            return;
        }

        $progressBar->setMessage(sprintf('Rows: %d', $rows));
        $progressBar->advance();
    }

    /**
     * @param string[] $excludedDirectories
     * @param string[] $allowedExtensions
     * @param string[] $excludedExtensions
     */
    private function countRows(
        string $directory,
        bool $includeHidden,
        array $excludedDirectories,
        array $allowedExtensions,
        array $excludedExtensions,
    ): int {
        $finder = $this->createFinder($directory, $includeHidden, $excludedDirectories);

        $count = 1; // root DIR
        /** @var array<string, bool> $seenDirs */
        $seenDirs = ['' => true];
        $rootPathReal = realpath($directory) ?: $directory;

        foreach ($finder as $entry) {
            $relativePath = $this->relativeToRoot($entry->getPathname(), $rootPathReal, $entry->getRelativePathname());
            $parentRelative = $this->parentRelativePath($relativePath);

            $path = $relativePath;
            while ($path !== '' && !isset($seenDirs[$path])) {
                $seenDirs[$path] = true;
                $count++;
                $path = $this->parentRelativePath($path);
            }

            if ($entry->isDir()) {
                continue;
            }

            $extension = strtolower(pathinfo($entry->getPathname(), PATHINFO_EXTENSION));
            if (!$this->shouldIncludeFile($extension, $allowedExtensions, $excludedExtensions)) {
                continue;
            }

            if (!isset($seenDirs[$parentRelative])) {
                $seenDirs[$parentRelative] = true;
                $count++;
            }

            $count++;
        }

        return $count;
    }

    private function dispatchDirectoryEvent(Directory $directory, int $probeLevel): void
    {
        if ($this->dispatcher === null) {
            return;
        }

        $this->dispatcher->dispatch(new ImportDirDirectoryEvent($directory, $probeLevel));
    }

    private function dispatchFileEvent(File $file, int $probeLevel, float $audioSimilarity): void
    {
        if ($this->dispatcher === null) {
            return;
        }

        $this->dispatcher->dispatch(new ImportDirFileEvent($file, $probeLevel, $audioSimilarity));
    }
}

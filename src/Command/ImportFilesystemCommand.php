<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

use function json_encode;
use function sprintf;
use function fopen;
use function fwrite;
use function fclose;
use function in_array;
use function pathinfo;
use function basename;
use function dirname;
use function strtolower;
use function trim;

#[AsCommand('import:filesystem', 'Scan local filesystem directories and output JSONL for processing')]
final class ImportFilesystemCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Directory path to scan')]
        string $directory,

        #[Option('Output JSONL file path')]
        string $output = 'files.jsonl',

        #[Option('File extensions to include (comma-separated)')]
        ?string $extensions = null,

        #[Option('Directories to exclude (comma-separated)')]
        ?string $excludeDirs = null,
    ): int {
        if (!is_dir($directory)) {
            $io->error(sprintf('Directory not found: %s', $directory));
            return Command::FAILURE;
        }

        // Parse extensions filter
        $allowedExtensions = [];
        if ($extensions !== null) {
            $allowedExtensions = array_map(
                static fn(string $ext): string => strtolower(trim($ext)),
                explode(',', $extensions)
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
        if (!empty($excludedDirectories)) {
            $io->note(sprintf('Excluding directories: %s', implode(', ', $excludedDirectories)));
        }
        $io->note(sprintf('Output: %s', $output));

        // Open output file for streaming
        $fh = fopen($output, 'w');
        if ($fh === false) {
            $io->error(sprintf('Cannot open output file: %s', $output));
            return Command::FAILURE;
        }

        $finder = new Finder();
        $finder->files()->in($directory);

        // Apply directory exclusions
        foreach ($excludedDirectories as $excludeDir) {
            $finder->notPath($excludeDir);
        }

        // Apply extension filter if specified
        if (!empty($allowedExtensions)) {
            $finder->name(sprintf('/\.(%s)$/i', implode('|', $allowedExtensions)));
        }

        $count = 0;
        $io->progressStart();

        foreach ($finder as $file) {
            $filepath = $file->getPathname();
            $relativePath = $file->getRelativePathname();
            
            $metadata = [
                'path' => $filepath,
                'relative_path' => $relativePath,
                'filename' => basename($filepath),
                'dirname' => dirname($filepath),
                'extension' => strtolower(pathinfo($filepath, PATHINFO_EXTENSION)),
                'size' => $file->getSize(),
                'modified_time' => $file->getMTime(),
                'is_readable' => $file->isReadable(),
            ];

            $jsonLine = json_encode($metadata) . "\n";
            $bytesWritten = fwrite($fh, $jsonLine);
            
            if ($bytesWritten === false) {
                $io->error(sprintf('Failed writing to output file: %s', $output));
                fclose($fh);
                return Command::FAILURE;
            }

            $count++;
            
            // Progress feedback every 10k files
            if ($count % 10000 === 0) {
                $io->progressAdvance(10000);
                $io->text(sprintf('Processed %d files...', $count));
            }
        }

        $io->progressFinish();
        fclose($fh);

        $io->success(sprintf('Successfully scanned %d files to %s', $count, $output));

        return Command::SUCCESS;
    }
}
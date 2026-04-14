<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

use function in_array;
use function sprintf;

#[AsCommand('import:filesystem', 'Deprecated compatibility wrapper; use import:dir for directory-aware filesystem scans')]
final class ImportFilesystemCommand
{
    public function __construct(
        private readonly ImportDirCommand $importDirCommand,
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
        #[Option('Deprecated. import:dir always emits directory rows for the tree.')]
        ?bool $tree = null,
        #[Option('Directories to exclude (comma-separated)')]
        ?string $excludeDirs = null,
        #[Option('Probe level: 0=fast path, 1=stat/mime/checksum, 2=deep (ffprobe/docx), 3=audio fingerprint (chromaprint)')]
        int $probe = 0,
        #[Option('Audio fingerprint similarity threshold (0.0 - 1.0)')]
        float $audioSimilarity = 0.90,
        #[Option('Deprecated. buckets maps to determinate progress; indeterminate/none map to import:dir defaults.')]
        string $progressMode = 'buckets',
        #[Option('Include dotfiles and dot directories')]
        bool $includeHidden = false,
        #[Option('Root id for this scan')]
        ?string $rootId = null,
        #[Option('Root path override for this scan')]
        ?string $rootPath = null,
    ): int {
        $io->warning('`import:filesystem` is deprecated and now delegates to `import:dir`.');
        $io->note('Use `import:dir` directly when you need directory rows and stable DIR/FILE DTO output.');

        if ($tree === false) {
            $io->warning('`--tree=0` is ignored. `import:dir` always emits directory rows because downstream imports need them.');
        }

        $showCount = match ($progressMode) {
            'buckets' => true,
            'indeterminate', 'none' => null,
            default => null,
        };

        if (!in_array($progressMode, ['buckets', 'indeterminate', 'none'], true)) {
            $io->warning(sprintf(
                'Unknown progress mode "%s"; delegating with import:dir default progress behavior.',
                $progressMode
            ));
        }

        return ($this->importDirCommand)(
            io: $io,
            directory: $directory,
            output: $output,
            extensions: $extensions,
            excludeExtensions: $excludeExtensions,
            excludeDirs: $excludeDirs,
            probe: $probe,
            audioSimilarity: $audioSimilarity,
            includeHidden: $includeHidden,
            rootId: $rootId,
            rootPath: $rootPath,
            showCount: $showCount,
        );
    }
}

<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service\Provider;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('survos.import.row_provider')]
final class JsonDirRowProvider implements RowProviderInterface
{
    public function __construct(
        private readonly RowProviderRegistry $registry,
    ) {
    }

    public function supports(string $ext): bool
    {
        return $ext === 'json_dir';
    }

    public function iterate(string $path, ProviderContext $ctx): \Generator
    {
        if (!\is_dir($path)) {
            throw new \RuntimeException(sprintf('Records directory "%s" does not exist.', $path));
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $ext  = strtolower($file->getExtension());
            $p    = $file->getPathname();

            if (!\in_array($ext, ['json', 'jsonl', 'csv'], true)) {
                continue;
            }

            // Delegate to the appropriate provider
            foreach ($this->registry->iterate($p, $ext, $ctx) as $row) {
                yield $row;
            }
        }
    }
}

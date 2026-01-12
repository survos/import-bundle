<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Command;

use Survos\ImportBundle\Contract\DatasetPathsFactoryInterface;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('import:browse', 'Browse JSONL files with pretty printing')]
final class ImportBrowseCommand
{
    public function __construct(
        private readonly ?DatasetPathsFactoryInterface $pathsFactory = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Dataset code (e.g. "forteobj")')]
        string $dataset,

        #[Option('File type to browse: raw or normalized')]
        string $type = 'normalized',

        #[Option('Number of records to display')]
        int $limit = 10,
    ): int {
        if ($this->pathsFactory === null) {
            $io->error('DatasetPathsFactoryInterface not registered. Enable museado/data-bundle or provide your own factory.');
            return Command::FAILURE;
        }

        if (!in_array($type, ['raw', 'normalized'], true)) {
            $io->error('Type must be "raw" or "normalized".');
            return Command::FAILURE;
        }

        $paths = $this->pathsFactory->for($dataset);
        
        $jsonlPath = match ($type) {
            'raw' => $paths->rawObjectPath,
            'normalized' => $paths->normalizedObjectPath,
        };

        if (!is_file($jsonlPath)) {
            $io->error(sprintf('JSONL file not found: %s', $jsonlPath));
            return Command::FAILURE;
        }

        $io->title(sprintf('Browsing %s records from %s', $type, $dataset));
        $io->note(sprintf('File: %s', $jsonlPath));

        $reader = JsonlReader::open($jsonlPath);
        $count = 0;

        foreach ($reader as $row) {
            $io->section(sprintf('Record #%d', $count + 1));
            $io->write(json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $io->write("\n\n");

            $count++;
            if ($count >= $limit) {
                break;
            }
        }

        $io->success(sprintf('Displayed %d records', $count));

        return Command::SUCCESS;
    }
}
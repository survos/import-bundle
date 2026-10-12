<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Command;

use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_file;
use function json_encode;
use function sprintf;

#[AsCommand('import:browse', 'Browse JSONL files with pretty printing')]
final class ImportBrowseCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('JSONL file path')]
        string $input,

        #[Option('Number of records to display')]
        int $limit = 10,
    ): int {
        $jsonlPath = $input;

        if (!is_file($jsonlPath)) {
            $io->error(sprintf('JSONL file not found: %s', $jsonlPath));
            return Command::FAILURE;
        }

        $io->title(sprintf('Browsing records from %s', $input));
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

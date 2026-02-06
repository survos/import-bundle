<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Command;

use Survos\ImportBundle\Contract\DatasetContextInterface;
use Survos\ImportBundle\Contract\DatasetPathsFactoryInterface;
use Survos\ImportBundle\Service\CsvProfileExporter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use RuntimeException;

use function basename;
use function dirname;
use function is_string;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function substr;

#[AsCommand('import:export:csv', 'Export a JSONL file to CSV using profile-derived headers')]
final class ImportExportCsvCommand
{
    public function __construct(
        private readonly string $dataDir,
        private readonly CsvProfileExporter $exporter,
        private readonly ?DatasetPathsFactoryInterface $pathsFactory = null,
        private readonly ?DatasetContextInterface $datasetContext = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Input JSONL path (optional if --profile or --dataset is provided)')]
        ?string $input = null,
        #[Option('Profile JSON path (optional if --dataset or input can infer it)')]
        ?string $profile = null,
        #[Option('Dataset code to infer profile/input paths')]
        ?string $dataset = null,
        #[Option('Override output CSV path')]
        ?string $output = null,
        #[Option('Max records to export (0 = no limit)')]
        ?int $limit = null,
    ): int {
        $io->title('Export CSV');

        $limit = ($limit !== null && $limit <= 0) ? null : $limit;

        $profilePath = $this->resolveProfilePath($profile, $dataset, $input);
        if ($profilePath === null || $profilePath === '') {
            $io->error('Missing profile. Provide --profile, --dataset, or an input path to infer it.');
            return Command::FAILURE;
        }

        try {
            $result = $this->exporter->exportFromProfile(
                $profilePath,
                $input,
                $output,
                $limit
            );
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Exported %d records to %s',
            $result['recordCount'],
            $result['outputPath']
        ));

        return Command::SUCCESS;
    }

    private function resolveProfilePath(?string $profile, ?string $dataset, ?string $input): ?string
    {
        if (is_string($profile) && $profile !== '') {
            return $profile;
        }

        if (($dataset === null || $dataset === '') && $this->datasetContext !== null && $this->datasetContext->has()) {
            $dataset = $this->datasetContext->getOrNull();
        }

        if (is_string($dataset) && $dataset !== '') {
            if ($this->pathsFactory !== null) {
                $paths = $this->pathsFactory->for($dataset);
                return $paths->profileObjectPath();
            }

            $dir = rtrim($this->dataDir, '/');
            return sprintf('%s/%s.profile.json', $dir, $dataset);
        }

        if (is_string($input) && $input !== '') {
            return $this->profilePathFromInput($input);
        }

        return null;
    }

    private function profilePathFromInput(string $inputPath): string
    {
        $dir = dirname($inputPath);
        $filename = basename($inputPath);

        if (str_ends_with($filename, '.gz')) {
            $filename = substr($filename, 0, -3);
        }

        $trimmed = preg_replace('/\.(jsonl|json|csv)$/i', '', $filename, 1);
        $trimmed = $trimmed ?? $filename;

        return sprintf('%s/%s.profile.json', $dir, $trimmed);
    }
}

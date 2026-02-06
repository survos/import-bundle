<?php
declare(strict_types=1);

namespace Survos\ImportBundle\EventListener;

use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Survos\ImportBundle\Service\CsvProfileExporter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

use function in_array;
use function str_ends_with;

final class ExportCsvOnConvertFinishedListener
{
    public function __construct(
        private readonly CsvProfileExporter $exporter,
    ) {
    }

    #[AsEventListener(event: ImportConvertFinishedEvent::class)]
    public function onFinished(ImportConvertFinishedEvent $event): void
    {
        if (!$this->shouldExport($event)) {
            return;
        }

        $inputPath = $this->resolveJsonlPath($event);
        if ($inputPath === null) {
            return;
        }

        $this->exporter->exportFromProfile(
            $event->profilePath,
            $inputPath,
            null,
            $event->limit
        );
    }

    private function shouldExport(ImportConvertFinishedEvent $event): bool
    {
        if ($this->isCsvPath($event->jsonlPath)) {
            return false;
        }

        return in_array('export:csv', $event->tags, true) || in_array('export.csv', $event->tags, true);
    }

    private function resolveJsonlPath(ImportConvertFinishedEvent $event): ?string
    {
        if ($this->isJsonlPath($event->jsonlPath)) {
            return $event->jsonlPath;
        }

        if ($this->isJsonlPath($event->input)) {
            return $event->input;
        }

        return null;
    }

    private function isJsonlPath(string $path): bool
    {
        return str_ends_with($path, '.jsonl') || str_ends_with($path, '.jsonl.gz');
    }

    private function isCsvPath(string $path): bool
    {
        return str_ends_with($path, '.csv') || str_ends_with($path, '.csv.gz');
    }
}

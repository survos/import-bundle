<?php
declare(strict_types=1);

namespace Survos\ImportBundle\EventListener;

use Survos\ImportBundle\Event\ImportDirDirectoryEvent;
use Survos\ImportBundle\Event\ImportDirFileEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

use function array_values;
use function basename;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function strtolower;
use function trim;

final class SampleImportDirEnrichmentListener
{
    #[AsEventListener(event: ImportDirDirectoryEvent::class)]
    public function onDirectory(ImportDirDirectoryEvent $event): void
    {
        $relativePath = $event->directory->relativePath;
        if ($relativePath === '') {
            return;
        }

        $name = trim(basename($relativePath));
        if ($name === '') {
            return;
        }

        if (preg_match('/^(.+)\s+family$/i', $name, $matches) === 1) {
            $family = trim((string) ($matches[1] ?? ''));
            if ($family !== '') {
                $event->directory->metadata['tags']['family'] = $this->appendUnique(
                    $event->directory->metadata['tags']['family'] ?? [],
                    $family,
                );
            }
        }
    }

    #[AsEventListener(event: ImportDirFileEvent::class)]
    public function onFile(ImportDirFileEvent $event): void
    {
        $extension = strtolower($event->file->fileInfo->extension);
        if ($extension === 'docx') {
            $event->file->tags = $this->appendUnique($event->file->tags, 'document:docx');
        }

        $author = $event->file->metadata['docx_author'] ?? null;
        if (is_string($author) && $author !== '') {
            $event->file->metadata['tags']['author'] = $this->appendUnique(
                $event->file->metadata['tags']['author'] ?? [],
                $author,
            );
        }
    }

    /**
     * @param mixed $values
     * @return string[]
     */
    private function appendUnique(mixed $values, string $value): array
    {
        $list = [];
        if (is_array($values)) {
            foreach ($values as $existing) {
                if (is_string($existing) && $existing !== '') {
                    $list[] = $existing;
                }
            }
        }

        if (!in_array($value, $list, true)) {
            $list[] = $value;
        }

        if (count($list) === 0) {
            return [];
        }

        return array_values($list);
    }
}

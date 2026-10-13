<?php

declare(strict_types=1);

namespace Survos\ImportBundle\EventListener;

use Survos\ImportBundle\Event\ImportDirDirectoryEvent;
use Survos\ImportBundle\Event\ImportDirFileEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class SampleImportDirEnrichmentListener
{
    #[AsEventListener(event: ImportDirDirectoryEvent::class)]
    public function onDirectory(ImportDirDirectoryEvent $event): void
    {
        $relativePath = $event->directory->relativePath;
        if ('' === $relativePath) {
            return;
        }

        $name = \trim(\basename($relativePath));
        if ('' === $name) {
            return;
        }

        if (1 === \preg_match('/^(.+)\s+family$/i', $name, $matches)) {
            $family = \trim((string) ($matches[1] ?? ''));
            if ('' !== $family) {
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
        $extension = \strtolower($event->file->fileInfo->extension);
        if ('docx' === $extension) {
            $event->file->tags = $this->appendUnique($event->file->tags, 'document:docx');
        }

        $author = $event->file->metadata['docx_author'] ?? null;
        if (\is_string($author) && '' !== $author) {
            $event->file->metadata['tags']['author'] = $this->appendUnique(
                $event->file->metadata['tags']['author'] ?? [],
                $author,
            );
        }
    }

    /**
     * @return string[]
     */
    private function appendUnique(mixed $values, string $value): array
    {
        $list = [];
        if (\is_array($values)) {
            foreach ($values as $existing) {
                if (\is_string($existing) && '' !== $existing) {
                    $list[] = $existing;
                }
            }
        }

        if (!\in_array($value, $list, true)) {
            $list[] = $value;
        }

        if (0 === \count($list)) {
            return [];
        }

        return \array_values($list);
    }
}

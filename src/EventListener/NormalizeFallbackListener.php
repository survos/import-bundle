<?php

declare(strict_types=1);

namespace Survos\ImportBundle\EventListener;

use Survos\DataContracts\Metadata\SyntheticSearchSummaryBuilder;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\DataContracts\Vocabulary\MuseumVocab;
use Survos\DimensionsBundle\Service\DimensionsNormalizer;
use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Final low-cost normalized-row enrichment shared by import consumers.
 */
#[AsEventListener(event: ImportConvertRowEvent::class, priority: -200)]
final class NormalizeFallbackListener
{
    public function __construct(
        private readonly SyntheticSearchSummaryBuilder $summaryBuilder = new SyntheticSearchSummaryBuilder(),
        private readonly ?DimensionsNormalizer $dimensionsNormalizer = null,
    ) {
    }

    public function __invoke(ImportConvertRowEvent $event): void
    {
        if ($event->row === null || $event->stage !== 'normalize') {
            return;
        }

        $row = &$event->row;

        if (!isset($row[ItemField::IIIF_BASE]) && isset($row[ItemField::LARGE_IMAGE_URL])) {
            $largeImageUrl = is_scalar($row[ItemField::LARGE_IMAGE_URL]) ? (string) $row[ItemField::LARGE_IMAGE_URL] : '';
            if (str_contains($largeImageUrl, '/iiif/') || str_ends_with($largeImageUrl, '/info.json')) {
                $row[ItemField::IIIF_BASE] = $largeImageUrl;
            }
        }

        $this->normalizeDimensions($row);

        // searchSummary disabled — it bloats the normalized rows and gets in the way.
        // if (empty($row[ItemField::SEARCH_SUMMARY])) {
        //     $row[ItemField::SEARCH_SUMMARY] = $this->summaryBuilder->build($row);
        // }
    }

    /** @param array<string,mixed> $row */
    private function normalizeDimensions(array &$row): void
    {
        if ($this->dimensionsNormalizer === null) {
            return;
        }

        $source = $row[MuseumVocab::DIMENSIONS] ?? null;
        if ($source === null) {
            return;
        }

        // Already structured (list of {height|width|...} records) — leave alone.
        if (is_array($source) && $this->isStructured($source)) {
            return;
        }

        $result = $this->dimensionsNormalizer->normalize($source);
        $row[MuseumVocab::DIMENSIONS] = $result['dimensions'];
        if ($result['dimensionsRaw'] !== null) {
            $row['dimensionsRaw'] = $result['dimensionsRaw'];
        }
        if ($result['weight'] !== null && !isset($row['weight'])) {
            $row['weight'] = $result['weight'];
        }
    }

    /** @param array<mixed> $source */
    private function isStructured(array $source): bool
    {
        foreach ($source as $row) {
            if (!is_array($row)) {
                return false;
            }
            foreach (['height', 'width', 'length', 'depth', 'radius', 'diameter', 'thickness'] as $key) {
                if (array_key_exists($key, $row)) {
                    return true;
                }
            }
            return false;
        }
        return false;
    }
}

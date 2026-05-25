<?php

declare(strict_types=1);

namespace Survos\ImportBundle\EventListener;

use Survos\DataContracts\Metadata\SyntheticSearchSummaryBuilder;
use Survos\DataContracts\Vocabulary\ItemField;
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
    ) {
    }

    public function __invoke(ImportConvertRowEvent $event): void
    {
        if ($event->row === null || $event->stage !== 'normalize') {
            return;
        }

        $row = &$event->row;

        if (!isset($row[ItemField::IIIF_BASE]) && isset($row[ItemField::LARGE_IMAGE_URL])) {
            $row[ItemField::IIIF_BASE] = $row[ItemField::LARGE_IMAGE_URL];
        }

        if (empty($row[ItemField::SEARCH_SUMMARY])) {
            $row[ItemField::SEARCH_SUMMARY] = $this->summaryBuilder->build($row);
        }
    }
}

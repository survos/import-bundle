<?php

declare(strict_types=1);

namespace Survos\ImportBundle\EventListener;

use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Survos\ImportBundle\Service\DtoClassResolver;
use Survos\ImportBundle\Service\DtoMapper;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Applies #[Map] alias mapping for the resolved dataset DTO.
 *
 * Runs at priority -100 — after all dataset-specific listeners have done
 * their complex extractions and transformations. The DTO's #[Map] attributes
 * then rename/alias the remaining fields and merge the result back into the row.
 *
 * Dataset-specific listeners handle exceptions (nested extraction, conditional
 * logic, computed fields). This listener handles the universal alias mapping.
 */
final class DtoMapRecordListener
{
    public function __construct(
        private readonly DtoClassResolver $resolver,
        private readonly DtoMapper $mapper,
    ) {}

    #[AsEventListener(event: ImportConvertRowEvent::class, priority: -100)]
    public function mapRecord(ImportConvertRowEvent $event): void
    {
        if ($event->row === null || $event->dataset === null) {
            return;
        }

        $dtoClass = $this->resolver->resolve($event->dataset);
        if ($dtoClass === null) {
            return;
        }

        $context = [
            'pixie' => str_contains($event->dataset, '/')
                ? explode('/', $event->dataset, 2)[1]
                : $event->dataset,
        ];

        $dto    = $this->mapper->mapRecord($event->row, $dtoClass, $context);
        $mapped = $this->mapper->toArray($dto);

        // Merge: DTO output wins over raw, but raw keys not in the DTO survive
        $event->row = array_filter(
            [...$event->row, ...$mapped],
            static fn(mixed $v): bool => $v !== null && $v !== '' && $v !== [],
        );
    }
}

<?php

declare(strict_types=1);

namespace Survos\ImportBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Survos\ImportBundle\Contract\FetchPagesInterface;
use Survos\ImportBundle\Entity\FetchPage;
use Survos\ImportBundle\Event\FetchPageStoredEvent;
use Survos\ImportBundle\Service\FetchAwareEntityRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class FetchPageCountUpdateListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FetchAwareEntityRegistry $registry,
    ) {
    }

    #[AsEventListener(event: FetchPageStoredEvent::class)]
    public function __invoke(FetchPageStoredEvent $event): void
    {
        $entityClass = $this->registry->classFor($event->page->providerCode);
        if (!$entityClass) {
            return;
        }

        $id = $this->datasetId($event->page->datasetKey);
        if (null === $id) {
            return;
        }

        $entity = $this->entityManager->find($entityClass, $id);
        if (!$entity instanceof FetchPagesInterface) {
            return;
        }

        $repo = $this->entityManager->getRepository(FetchPage::class);
        $total = $repo->count([
            'providerCode' => $event->page->providerCode,
            'datasetKey' => $event->page->datasetKey,
            'kind' => $event->page->kind,
        ]);
        $fetched = $repo->count([
            'providerCode' => $event->page->providerCode,
            'datasetKey' => $event->page->datasetKey,
            'kind' => $event->page->kind,
            'status' => FetchPage::STATUS_FETCHED,
        ]);

        if (FetchPage::KIND_LISTING === $event->page->kind) {
            $entity->listingPageCount = $total;
            $entity->listingPagesFetched = $fetched;
        } elseif (FetchPage::KIND_DETAIL === $event->page->kind) {
            $entity->detailPageCount = $total;
            $entity->detailPagesFetched = $fetched;
        }

        $this->entityManager->flush();
    }

    private function datasetId(string $datasetKey): string|int|null
    {
        $parts = explode('/', $datasetKey, 2);

        return $parts[1] ?? null;
    }
}

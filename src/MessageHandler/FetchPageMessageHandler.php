<?php
declare(strict_types=1);

namespace Survos\ImportBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\ImportBundle\Entity\FetchPage;
use Survos\ImportBundle\Entity\FetchRecord;
use Survos\ImportBundle\Event\FetchPageFetchedEvent;
use Survos\ImportBundle\Event\FetchPageStoredEvent;
use Survos\ImportBundle\Message\FetchPageMessage;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final class FetchPageMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function __invoke(FetchPageMessage $message): void
    {
        /** @var FetchPage|null $page */
        $page = $this->entityManager->find(FetchPage::class, $message->fetchPageId);
        if (!$page) {
            throw new \RuntimeException(sprintf('FetchPage %d not found.', $message->fetchPageId));
        }

        $page->status = FetchPage::STATUS_FETCHING;
        $page->error = null;
        $this->entityManager->flush();

        try {
            $this->logger->info('fetch page start', [
                'id' => $page->id,
                'dataset' => $page->datasetKey,
                'kind' => $page->kind,
                'page' => $page->pageNumber,
                'url' => $page->url,
            ]);

            $response = $this->fetchWithRetry($page);
            $body = $response->getContent();
            $event = new FetchPageFetchedEvent(
                $page,
                $body,
                $response->getHeaders(false),
                $response->getStatusCode(),
            );
            $this->eventDispatcher->dispatch($event);

            $this->logger->info('fetch page parsed', [
                'id' => $page->id,
                'dataset' => $page->datasetKey,
                'kind' => $page->kind,
                'page' => $page->pageNumber,
                'statusCode' => $event->statusCode,
                'bytes' => strlen($body),
                'rows' => count($event->rows),
                'archivePath' => $event->archivePath,
                'contentType' => $event->contentType ?? ($response->getHeaders(false)['content-type'][0] ?? null),
            ]);

            if ($event->rows === []) {
                throw new \RuntimeException(sprintf('No rows were produced for %s page %d.', $page->datasetKey, $page->pageNumber));
            }

            foreach ($this->entityManager->getRepository(FetchRecord::class)->forPage($page) as $existing) {
                $this->entityManager->remove($existing);
            }

            $written = 0;
            foreach (array_values($event->rows) as $rowNumber => $row) {
                $payload = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($payload === false) {
                    throw new \RuntimeException(sprintf('Failed encoding row %d for %s page %d.', $rowNumber, $page->datasetKey, $page->pageNumber));
                }

                $record = new FetchRecord($page, $rowNumber, $payload);
                $record->sourceId = isset($row['id']) && is_scalar($row['id']) ? (string) $row['id'] : null;
                $record->recordType = isset($row['_recordType']) && is_scalar($row['_recordType'])
                    ? (string) $row['_recordType']
                    : (isset($row['type']) && is_scalar($row['type']) ? (string) $row['type'] : null);
                $this->entityManager->persist($record);
                $written++;
            }

            $page->archivePath = $event->archivePath;
            $page->recordCount = $written;
            $page->contentType = $event->contentType ?? ($response->getHeaders(false)['content-type'][0] ?? null);
            $page->fetchedAt = new \DateTimeImmutable();
            $page->status = FetchPage::STATUS_FETCHED;
            $this->entityManager->flush();

            $this->logger->info('fetch page stored', [
                'id' => $page->id,
                'dataset' => $page->datasetKey,
                'kind' => $page->kind,
                'page' => $page->pageNumber,
                'rowsWrittenToDb' => $written,
                'archivePath' => $page->archivePath,
                'storage' => $page->archivePath ? 'archive+db' : 'db-only',
            ]);

            $this->eventDispatcher->dispatch(new FetchPageStoredEvent($page, $event->archivePath, $written));
        } catch (\Throwable $e) {
            $page->status = FetchPage::STATUS_FAILED;
            $page->error = $e->getMessage();
            $this->entityManager->flush();

            $this->logger->warning('fetch page failed', [
                'id' => $page->id,
                'dataset' => $page->datasetKey,
                'page' => $page->pageNumber,
                'url' => $page->url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function fetchWithRetry(FetchPage $page): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $last = null;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $response = $this->httpClient->request('GET', $page->url, [
                    'headers' => array_filter([
                        'Accept' => $page->accept ?? 'text/html,application/xhtml+xml',
                        'User-Agent' => 'Museado/1.0 (contact: tacman@gmail.com)',
                    ]),
                    'timeout' => 30,
                ]);

                if ($response->getStatusCode() !== 200) {
                    throw new \RuntimeException(sprintf('HTTP %d', $response->getStatusCode()));
                }

                return $response;
            } catch (\Throwable $e) {
                $last = $e;
                $this->logger->warning('fetch page retry', [
                    'dataset' => $page->datasetKey,
                    'page' => $page->pageNumber,
                    'attempt' => $attempt,
                    'url' => $page->url,
                    'error' => $e->getMessage(),
                ]);
                usleep($attempt * 300_000);
            }
        }

        throw new \RuntimeException(sprintf('Failed fetching %s after retries (%s)', $page->url, $last?->getMessage() ?? 'unknown'));
    }
}

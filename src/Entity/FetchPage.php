<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\ImportBundle\Repository\FetchPageRepository;

#[ORM\Entity(repositoryClass: FetchPageRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_fetch_page', columns: ['provider_code', 'dataset_key', 'kind', 'page_number'])]
class FetchPage
{
    public const string KIND_LISTING = 'listing';
    public const string KIND_DETAIL = 'detail';

    public const string TRANSPORT_LISTING = 'fetch_listing_pages';
    public const string TRANSPORT_DETAIL = 'fetch_detail_pages';

    public const string STATUS_NEW = 'new';
    public const string STATUS_FETCHING = 'fetching';
    public const string STATUS_FETCHED = 'fetched';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_MERGED = 'merged';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 32)]
    public string $providerCode;

    #[ORM\Column(length: 190)]
    public string $datasetKey;

    #[ORM\Column(length: 32)]
    public string $kind = self::KIND_LISTING;

    #[ORM\Column]
    public int $pageNumber = 1;

    #[ORM\Column(length: 2048)]
    public string $url;

    #[ORM\Column(length: 32)]
    public string $status = self::STATUS_NEW;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $contentType = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $accept = null;

    #[ORM\Column(length: 2048, nullable: true)]
    public ?string $archivePath = null;

    #[ORM\Column(nullable: true)]
    public ?int $recordCount = null;

    #[ORM\Column(length: 1024, nullable: true)]
    public ?string $error = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $fetchedAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $meta = null;

    public function __construct(
        string $providerCode,
        string $datasetKey,
        int $pageNumber,
        string $url,
        string $kind = self::KIND_LISTING,
    ) {
        $this->providerCode = $providerCode;
        $this->datasetKey = $datasetKey;
        $this->pageNumber = $pageNumber;
        $this->url = $url;
        $this->kind = $kind;
    }

    public function transportName(): string
    {
        return match ($this->kind) {
            self::KIND_DETAIL => self::TRANSPORT_DETAIL,
            default => self::TRANSPORT_LISTING,
        };
    }
}

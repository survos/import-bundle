<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\ImportBundle\Repository\FetchRecordRepository;

#[ORM\Entity(repositoryClass: FetchRecordRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_fetch_record_page_row', columns: ['fetch_page_id', 'row_number'])]
class FetchRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FetchPage::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public FetchPage $fetchPage;

    #[ORM\Column(length: 32)]
    public string $providerCode;

    #[ORM\Column(length: 190)]
    public string $datasetKey;

    #[ORM\Column(length: 32)]
    public string $kind;

    #[ORM\Column]
    public int $rowNumber = 0;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $sourceId = null;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $recordType = null;

    #[ORM\Column(type: Types::TEXT)]
    public string $payload;

    #[ORM\Column(nullable: true)]
    public ?int $payloadBytes = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct(FetchPage $fetchPage, int $rowNumber, string $payload)
    {
        $this->fetchPage = $fetchPage;
        $this->providerCode = $fetchPage->providerCode;
        $this->datasetKey = $fetchPage->datasetKey;
        $this->kind = $fetchPage->kind;
        $this->rowNumber = $rowNumber;
        $this->payload = $payload;
        $this->payloadBytes = strlen($payload);
        $this->createdAt = new \DateTimeImmutable();
    }
}

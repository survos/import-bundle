<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Tests\Entity;

use DateTimeImmutable;

final class Sample
{
    public ?int $id = null;
    public array $tags = [];              // arrays via delimiter or JSON
    public array $codes = [];             // arrays via delimiter or JSON
    public ?float $rating = null;         // numeric coercion
    public ?bool $isActive = null;        // bool coercion ("is*" prefix, 1/0)
    public ?DateTimeImmutable $createdAt = null;  // Date coercion
    public mixed $meta = null;            // JSON object coercion accepted here
    public ?string $notes = null;         // untouched
}

<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Entity\Traits;

use Doctrine\ORM\Mapping as ORM;

trait FetchPagesTrait
{
    #[ORM\Column(nullable: true)]
    public ?int $listingPageCount = null;

    #[ORM\Column(nullable: true)]
    public ?int $listingPagesFetched = null;

    #[ORM\Column(nullable: true)]
    public ?int $detailPageCount = null;

    #[ORM\Column(nullable: true)]
    public ?int $detailPagesFetched = null;
}

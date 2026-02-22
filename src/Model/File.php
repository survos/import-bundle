<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Model;

use Survos\ImportBundle\Contract\HasFileInfoInterface;

use function is_array;
use function is_string;

final class File implements HasFileInfoInterface
{
    use FileInfoTrait;

    /** @var array<string, mixed> */
    public array $metadata = [];

    /** @var string[] */
    public array $tags = [];

    public function __construct(
        public string $id,
        public string $parentId,
        public string $rootId,
        public string $relativePath,
        public int $probeLevel,
        FinderFileInfo $fileInfo,
    ) {
        $this->fileInfo = $fileInfo;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'FILE',
            'id' => $this->id,
            'parent_id' => $this->parentId,
            'root_id' => $this->rootId,
            'relative_path' => $this->relativePath,
            'probe_level' => $this->probeLevel,
            'tags' => $this->tags,
            'file_info' => $this->fileInfo->toArray(),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $dto = new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            parentId: is_string($data['parent_id'] ?? null) ? $data['parent_id'] : '',
            rootId: is_string($data['root_id'] ?? null) ? $data['root_id'] : '',
            relativePath: is_string($data['relative_path'] ?? null) ? $data['relative_path'] : '',
            probeLevel: (int) ($data['probe_level'] ?? 0),
            fileInfo: FinderFileInfo::fromArray(is_array($data['file_info'] ?? null) ? $data['file_info'] : []),
        );

        $dto->tags = is_array($data['tags'] ?? null) ? $data['tags'] : [];
        $dto->metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        return $dto;
    }
}

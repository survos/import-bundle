<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Model;

use Symfony\Component\Finder\SplFileInfo;

use function array_key_exists;
use function basename;
use function dirname;
use function is_string;
use function pathinfo;
use function strtolower;

final class FinderFileInfo
{
    public function __construct(
        public string $pathname,
        public string $relativePathname,
        public string $relativePath,
        public string $filename,
        public string $basename,
        public string $dirname,
        public string $extension,
        public bool $isReadable,
        public bool $isDir,
        public ?int $size = null,
        public ?int $createdTime = null,
        public ?int $modifiedTime = null,
    ) {
    }

    public static function fromSplFileInfo(SplFileInfo $file, string $relativePathname): self
    {
        $isDir = $file->isDir();
        $size = null;
        if (!$isDir) {
            try {
                $size = $file->getSize();
            } catch (\Throwable $e) {
                // File has I/O error - log to stderr but continue
                error_log(sprintf('Warning: getSize() failed for %s: %s', $file->getPathname(), $e->getMessage()));
                $size = null;
            }
        }

        // Try to get timestamps, but handle I/O errors gracefully
        $createdTime = null;
        $modifiedTime = null;
        try {
            $createdTime = $file->getCTime();
        } catch (\Throwable $e) {
            error_log(sprintf('Warning: getCTime() failed for %s: %s', $file->getPathname(), $e->getMessage()));
        }
        try {
            $modifiedTime = $file->getMTime();
        } catch (\Throwable $e) {
            error_log(sprintf('Warning: getMTime() failed for %s: %s', $file->getPathname(), $e->getMessage()));
        }

        return new self(
            pathname: $file->getPathname(),
            relativePathname: $relativePathname,
            relativePath: $file->getRelativePath(),
            filename: $file->getFilename(),
            basename: basename($file->getPathname()),
            dirname: dirname($file->getPathname()),
            extension: strtolower(pathinfo($file->getPathname(), PATHINFO_EXTENSION)),
            isReadable: $file->isReadable(),
            isDir: $isDir,
            size: $size,
            createdTime: $createdTime,
            modifiedTime: $modifiedTime,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pathname: is_string($data['pathname'] ?? null) ? $data['pathname'] : '',
            relativePathname: is_string($data['relative_pathname'] ?? null) ? $data['relative_pathname'] : '',
            relativePath: is_string($data['relative_path'] ?? null) ? $data['relative_path'] : '',
            filename: is_string($data['filename'] ?? null) ? $data['filename'] : '',
            basename: is_string($data['basename'] ?? null) ? $data['basename'] : '',
            dirname: is_string($data['dirname'] ?? null) ? $data['dirname'] : '',
            extension: is_string($data['extension'] ?? null) ? strtolower($data['extension']) : '',
            isReadable: (bool) ($data['is_readable'] ?? false),
            isDir: (bool) ($data['is_dir'] ?? false),
            size: array_key_exists('size', $data) ? (is_string($data['size']) ? (int) $data['size'] : $data['size']) : null,
            createdTime: array_key_exists('created_time', $data) ? (is_string($data['created_time']) ? (int) $data['created_time'] : $data['created_time']) : null,
            modifiedTime: array_key_exists('modified_time', $data) ? (is_string($data['modified_time']) ? (int) $data['modified_time'] : $data['modified_time']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pathname' => $this->pathname,
            'relative_pathname' => $this->relativePathname,
            'relative_path' => $this->relativePath,
            'filename' => $this->filename,
            'basename' => $this->basename,
            'dirname' => $this->dirname,
            'extension' => $this->extension,
            'is_readable' => $this->isReadable,
            'is_dir' => $this->isDir,
            'size' => $this->size,
            'created_time' => $this->createdTime,
            'modified_time' => $this->modifiedTime,
        ];
    }
}

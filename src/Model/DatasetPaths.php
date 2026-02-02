<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Model;

use function basename;
use function dirname;
use function preg_replace;
use function sprintf;

/**
 * Immutable value object describing all canonical paths
 * associated with a dataset.
 *
 * This object is shared across commands:
 *  - import:convert
 *  - import:entities
 *  - terms / termset tooling
 */
final class DatasetPaths
{
    public function __construct(
        public readonly string $datasetKey,
        public readonly string $datasetRoot,

        // Raw input
        public readonly string $rawDir,
        public readonly string $rawObjectPath,

        // Normalized output
        public readonly string $normalizedDir,
        public readonly string $normalizedObjectPath,

        // Terms / termsets
        public readonly string $termsDir,

        // Profile output (optional override)
        public readonly ?string $profileDir = null,
    ) {}

    public function profileObjectPath(): string
    {
        $dir = $this->profileDir ?? dirname($this->normalizedObjectPath);
        $base = basename($this->normalizedObjectPath);

        $base = preg_replace('/\.jsonl$/i', '', $base, 1);

        return sprintf('%s/%s.profile.json', $dir, $base);
    }
}

# `import:dir` filesystem indexing

`import:dir` scans a directory and emits a JSONL archive of **DTO rows**:

- `DIR` rows for every directory (including root)
- `FILE` rows for every included file
- deterministic `id` and `parent_id` links so the tree can be rebuilt quickly

This is designed for one-time expensive probing (metadata extraction, OCR, AI tags) so downstream commands can reuse the JSONL and avoid re-reading the filesystem.

## Why this format

- Every non-root row has a `parent_id` that points to a `DIR` row
- DTO payload avoids large ad-hoc metadata arrays duplicated in command code
- Probe/enrichment listeners can add metadata once, then all later workflows can consume it
- Sidecar data can be attached per file (`<path>.sidecar.json`) to help with resumability and slow pipelines

## Command usage

```bash
bin/console import:dir /data/archive \
  --output=data/archive.scan.jsonl \
  --probe=2 \
  --show-count=true \
  --exclude-dirs=.git,node_modules \
  --extensions=jpg,png,docx,pdf
```

### Key options

- `--probe=0..3`
  - `0`: fast structural scan
  - `1`: stat + mime + checksum + sidecar load
  - `2`: deep probe (media duration/docx core metadata)
  - `3`: audio fingerprint + similarity detection
- `--show-count=true` enables determinate progress by pre-counting rows
- `--show-count` omitted (or false) uses indeterminate progress (no pre-scan)

## Row shape

All rows include:

- `type`: `DIR` or `FILE`
- `id`: deterministic row id
- `parent_id`: `null` for root DIR, otherwise parent directory id
- `root_id`
- `relative_path`
- `file_info`: serialized `FinderFileInfo`
- `tags`
- `metadata`

Example (`FILE`):

```json
{
  "type": "FILE",
  "id": "2f9dbf...",
  "parent_id": "8b8ae2...",
  "root_id": "archive",
  "relative_path": "family/smith/scan-001.docx",
  "probe_level": 2,
  "file_info": {
    "pathname": "/data/archive/family/smith/scan-001.docx",
    "relative_pathname": "family/smith/scan-001.docx",
    "relative_path": "family/smith",
    "filename": "scan-001.docx",
    "basename": "scan-001.docx",
    "dirname": "/data/archive/family/smith",
    "extension": "docx",
    "is_readable": true,
    "is_dir": false,
    "size": 173845,
    "created_time": 1739981224,
    "modified_time": 1739981224
  },
  "tags": ["family", "smith"],
  "metadata": {
    "mime_type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "docx_author": "A. Smith",
    "media_duration": null,
    "sidecar": {
      "rows": 1,
      "bytes": 173845,
      "completed": true
    },
    "probe_duration_ms": 14
  }
}
```

## Deserialize JSONL rows back into DTOs

```php
<?php
declare(strict_types=1);

use Survos\ImportBundle\Model\Directory;
use Survos\ImportBundle\Model\File;
use Survos\JsonlBundle\IO\JsonlReader;

$reader = JsonlReader::open('data/archive.scan.jsonl');

foreach ($reader->lines() as $row) {
    $type = $row['type'] ?? null;

    if ($type === 'DIR') {
        $dir = Directory::fromArray($row);
        // persist directory entity, build in-memory tree, etc.
        continue;
    }

    if ($type === 'FILE') {
        $file = File::fromArray($row);
        // persist file entity with probe/AI metadata
    }
}
```

## Enrichment via listeners

The bundle dispatches:

- `Survos\ImportBundle\Event\ImportDirDirectoryEvent`
- `Survos\ImportBundle\Event\ImportDirFileEvent`

Listeners can enrich metadata/tags (family names, OCR text, embeddings, custom taxonomy) during scan. Because this is serialized into JSONL, later steps (profile/index/import-to-DB) can reuse results without repeating expensive extraction.

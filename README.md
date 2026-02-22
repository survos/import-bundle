# SurvosImportBundle

Symfony bundle that provides tools for importing data.

SurvosImportBundle helps you get **raw CSV/JSON data into your database via Doctrine** with minimal fuss.

Typical problems this bundle solves:

- You have **CSV or JSON exports** (from an API, a vendor, a legacy system…) and you want them in your app’s database.
- You need a **real primary key**, correct **Doctrine field types** (int, float, bool, datetime, json, text…), and ideally some **basic statistics** to make good schema decisions.
- You want a **repeatable pipeline** that goes from:
    1. Raw file → cleaned, normalized **JSONL + profile**
    2. JSONL + profile → **Doctrine entity** with good defaults
    3. JSONL → **Doctrine entities persisted** efficiently (batches, progress, etc.)

SurvosImportBundle provides exactly that pipeline:

1. `import:convert` – convert raw CSV/JSON into JSONL + a profile with field statistics.
2. `code:entity` – generate a Doctrine entity from that profile (via SurvosCodeBundle).
3. `import:entities` – import JSONL records into your database using Doctrine.

You can also use it in a simpler “direct CSV → Entity → Import” mode for quick one-off jobs and demos.

---

## Table of Contents

1. [Installation](#installation)
2. [Quick Start (Direct CSV → Entity → Import)](#quick-start-direct-csv--entity--import)
3. [Concepts](#concepts)
    - [JSONL](#jsonl)
    - [Profile](#profile)
4. [The Pipeline](#the-pipeline)
    - [1. import:convert](#1-importconvert)
    - [2. code:entity](#2-codeentity)
    - [3. import:entities](#3-importentities)
5. [End-to-End Example](#end-to-end-example)
6. [Complete Demo App with EasyAdmin](#complete-demo-app-with-easyadmin)
7. [Castor Automation](#castor-automation)
8. [Events & Extensibility](#events--extensibility)
9. [Filesystem Indexing (`import:dir`)](#filesystem-indexing-importdir)
10. [Tips & Gotchas](#tips--gotchas)
11. [See Also](#see-also)

---

## Installation

```bash
composer require survos/import-bundle
composer require --dev survos/code-bundle
```

Register the bundle if you’re not using auto-discovery:

```php
// config/bundles.php
return [
    Survos\ImportBundle\SurvosImportBundle::class => ['all' => true],
];
```

---

## Quick Start (Direct CSV → Entity → Import)

This is the minimal “I just want my CSV in Doctrine” flow.

In short, install the bundles:

```bash
composer req survos/import-bundle
composer req --dev survos/code-bundle
```

First, create an entity class by inspecting the first line (and/or a sample) of a CSV file:

```bash
bin/console code:entity Movie --file=data/movies.csv
```

The entity has property names that loosely match the CSV headers  
(e.g. `"First Name"` becomes `$firstName` in the entity).

Then import the data:

```bash
bin/console import:entities Movie --file data/movies.csv --limit 500
```

That’s the “fast path” for simple, flat CSVs.

For more control and richer metadata, use the JSONL-based pipeline below.

---

## Concepts

### JSONL

The bundle normalizes input into **JSON Lines (JSONL)**:

- One JSON object **per line**
- Easy to stream in batches
- Unix-friendly
- Plays nicely with SurvosJsonlBundle and other ETL tools

Example (`movies.jsonl`):

```json
{"id": 1, "title": "The Matrix", "year": 1999}
{"id": 2, "title": "Inception", "year": 2010}
```

### Profile

Conversion also generates a **profile** (`*.profile.json`) containing:

- Field type inference
- Null count, distinct count
- String length stats
- Boolean-like detection
- Facet candidate detection
- Primary key candidates
- First/last samples
- Min/max distributions

This powers `code:entity` to emit correct Doctrine field mappings (e.g. using `Types::TEXT` when max length > 255).

---

## The Pipeline

### 1. `import:convert`

**Goal:** Transform CSV/JSON/ZIP/GZ input into:

- A normalized `*.jsonl` file
- A detailed `*.profile.json` file

**Usage:**

```bash
bin/console import:convert data/movies.csv --dataset=movies
```

Features:

- Detects CSV / JSON / JSONL / ZIP / GZIP automatically
- Normalizes encoding
- Produces JSONL for streaming
- Produces a profile with complete field statistics
- Supports `--limit`, `--tags`, `--dataset`

---

### 2. `code:entity`
*(from SurvosCodeBundle, but part of this pipeline)*

**Goal:** Generate a Doctrine entity from a JSONL profile.

Example:

```bash
bin/console code:entity data/movies.profile.json App\\Entity\\Movie
```

What it infers:

- Primary key (or use `--pk`)
- Doctrine field types:
    - small strings → `string`
    - long strings (length > 255) → `Types::TEXT`
    - ints/floats
    - datetime/dates
    - json for nested structures
- Public properties with helpful PHPDoc derived from the profile
- `#[ORM\Entity(repositoryClass: ...)]`

You review/tweak it, then generate schema/migrations.

---

### 3. `import:entities`

**Goal:** Insert the JSONL data into your database using Doctrine.

Example:

```bash
bin/console import:entities App\\Entity\\Movie data/movies.jsonl
```

Key features:

- Batch processing (`--batch=200`)
- PK assignment via `--pk`
- Reset/truncate via `--reset`
- Progress bar
- Works with any Doctrine entity

---

## End-to-End Example

### Step 1 — Convert CSV → JSONL + profile

```bash
bin/console import:convert data/movies.csv --dataset=movies
```

Produces:

- `data/movies.jsonl`
- `data/movies.profile.json`

### Step 2 — Generate Doctrine entity

```bash
bin/console code:entity data/movies.profile.json App\\Entity\\Movie --pk=id
```

Creates something like:

```php
#[ORM\Entity(repositoryClass: MovieRepository::class)]
class Movie
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $title = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $year = null;

    // ...
}
```

### Step 3 — Import entities

```bash
bin/console import:entities App\\Entity\\Movie data/movies.jsonl --pk=id
```

Done — your DB is now populated.

---

## Complete Demo App with EasyAdmin

This is a complete “from scratch” demo using EasyAdmin to view the data.

### Prerequisites

- symfony CLI
- curl
- PHP 8.4 (the demo uses property hooks)
- gunzip (because the demo data is gzipped)

### Commands

```bash
symfony new import-demo --webapp  && cd import-demo
composer config extra.symfony.allow-contrib true
echo "DATABASE_URL=sqlite:///%kernel.project_dir%/var/data.db" > .env.local
symfony server:start -d

composer req --dev survos/code-bundle
composer req survos/import-bundle league/csv
composer req easycorp/easyadmin-bundle:4.x-dev

mkdir -p data
curl -L -o data/movies.csv.gz https://github.com/metarank/msrd/raw/master/dataset/movies.csv.gz
gunzip data/movies.csv.gz

# sanity check
head -n 2 data/movies.csv

# generate entity from CSV
bin/console code:entity Movie --file=data/movies.csv

# create schema
bin/console d:sch:update --force

# import some data
bin/console import:entities Movie --file data/movies.csv --limit 500

# EasyAdmin dashboard + CRUD
bin/console make:admin:dashboard -n
bin/console make:admin:crud App\\Entity\\Movie -n
```

For reasons that are still a bit mysterious, clearing the cache inline doesn’t always work, so run:

```bash
bin/console cache:clear
bin/console cache:pool:clear cache.app
symfony open:local --path=/admin/movie
```

---

## Castor Automation

Instead of the bash script above, you can run everything as a Castor command, after installing Castor:

```bash
curl "https://castor.jolicode.com/install" | bash
```

Now create a project, download the castor file and build using it:

```bash
symfony new import-demo --webapp && cd import-demo 

curl -L https://github.com/survos/import-bundle/raw/master/app/castor.php -o castor.php

castor build
```

This will scaffold the demo, run imports, and set up admin views in one go.

---

## Events & Extensibility

SurvosImportBundle emits events so you can **tweak records on the fly** during conversion/import.  
The three main ImportBundle events are:

1. `ImportConvertStartedEvent`
    - Emitted when an import/convert run starts.
    - Carries dataset name, input path, limit, tags, etc.
    - Good place for initialization, logging, or dataset-specific setup.

2. `ImportConvertRowEvent`
    - Emitted for **every row** during conversion.
    - Lets you mutate, enrich, or even drop records before they are written to JSONL.
    - You can:
        - Normalize IDs
        - Slugify codes
        - Attach derived URLs
        - Store images to disk
        - Deduplicate by tracking `$event->index`/keys

3. `ImportConvertFinishedEvent`
    - Emitted when conversion finishes.
    - Good for summaries, flushing caches, or post-processing.

You can also listen to JsonlBundle’s events (e.g. `JsonlConvertStartedEvent`, `JsonlRecordEvent`) for lower-level control of JSONL conversion.

### Example: Enriching Records During Conversion

Here’s a simplified example based on a real service used in this bundle’s demos:

```php
<?php

namespace App\Service;

use Survos\CoreBundle\Service\SurvosUtils;
use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\String\Slugger\SluggerInterface;

class EnhanceRecordService
{
    /** @var string[] */
    private array $seen = [];

    public function __construct(
        private SluggerInterface $asciiSlugger,
    ) {}

    #[AsEventListener(event: ImportConvertRowEvent::class)]
    public function tweakRecord(ImportConvertRowEvent $event): void
    {
        $record = $event->row;

        // Clean up nulls / empty arrays
        $record = SurvosUtils::removeNullsAndEmptyArrays($record);

        switch ($event->dataset) {
            case 'wcma':
                $id = (int) $record['id'];

                // De-dupe by ID
                if (in_array($id, $this->seen, true)) {
                    // Drop this row entirely
                    $event->row = null;
                    return;
                }

                $this->seen[] = $id;

                // Normalize ID and build useful URLs
                $record['id'] = $id;
                $record['citation_url'] = sprintf(
                    'https://egallery.williams.edu/objects/%d',
                    $id
                );
                $record['manifest'] = sprintf(
                    'https://egallery.williams.edu/apis/iiif/presentation/v2/1-objects-%d/manifest',
                    $id
                );
                break;

            case 'marvel':
                // Slug based on name for a stable "code"
                $code = $this->asciiSlugger->slug($record['name'])->toString();
                $record['code'] = $code;

                if (in_array($code, $this->seen, true)) {
                    $event->row = null; // skip duplicates
                    return;
                }

                $this->seen[] = $code;
                break;

            case 'car':
                // Assign a synthetic ID using the row index
                $record['id'] = $event->index + 1;
                break;
        }

        // Save modified record back onto the event
        $event->row = $record;
    }
}
```

You can also attach helpers, for example to store base64 images as files and replace the JSON field with a URL:

```php
private function saveBase64Image(string $base64String, string $outputPath): bool
{
    $dir = \dirname($outputPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $matches)) {
        $base64String = substr($base64String, strpos($base64String, ',') + 1);
    }

    $imageData = base64_decode($base64String, true);
    if ($imageData === false) {
        return false;
    }

    return file_put_contents($outputPath, $imageData) !== false;
}
```

This pattern—**listen to events and mutate `$event->row`**—is the recommended way to inject domain-specific logic into a generic import pipeline without forking the bundle.

---

## Filesystem Indexing (`import:dir`)

Use `import:dir` when the source of truth is a directory tree and your pipeline needs reusable filesystem/probe metadata.

- Emits linked `DIR` + `FILE` JSONL DTO rows with deterministic `id` and `parent_id`
- Lets listeners enrich rows with domain metadata (family tags, OCR text, AI outputs)
- Attaches probe/sidecar information once so downstream workflows do not repeat expensive extraction

See `docs/import-dir.md` for full options, DTO schema, and deserialize examples.

---

## Tips & Gotchas

- **Type errors during import**  
  Usually caused by wrong `--pk` or mismatched types.  
  Re-check the profile and/or adjust the entity types.

- **Long text fields**  
  Over 255 chars → mapped to `Types::TEXT` by `code:entity`.  
  If the data changes shape later, regenerate or tweak manually.

- **Nested structures**  
  Complex JSON structures are mapped to Doctrine’s `json` type.  
  Make sure your database platform supports it.

- **Iterate fast**  
  Use `--limit` during development:
    - Faster profiling
    - Less noise
    - Regenerate the full JSONL once the entity looks good.

---

## See Also

- **SurvosJsonlBundle** — JSONL utilities, enrichment, pipelines
- **SurvosCodeBundle** — entity generation, Twig/JS/Liquid template generation
- **SurvosMeiliBundle** — search and indexing once entities are in Doctrine

---

# Typesense Schemas for Survos / Museado / ScanStation
## Vibe-coding reference — schema design and ingestion pipeline

---

## Is the Schema Format a Standard?

**No.** Typesense's collection schema is a proprietary JSON format. It is not:

- **JSON Schema** (draft-07, 2020-12) — which describes data shape for validation only, has no
  concept of `facet`, `sort`, `infix`, `locale`, or search-engine indexing behavior
- **OpenAPI** — which describes an entire API surface, not index configurations
- **Elasticsearch mappings** — superficially similar but incompatible in detail

Typesense *does* publish an OpenAPI spec describing their own REST API
(`github.com/typesense/typesense-api-spec/openapi.yml`), so the endpoints are
OpenAPI-documented — but the JSON body you POST to create a collection is Typesense-specific.

There is no converter from JSON Schema, OpenAPI component schemas, or Doctrine/API Platform
metadata. The bridge has to be written — which is why the PHP Attribute approach below is the
right investment.

---

## The Schema JSON: What You Actually POST

`POST /collections` with `Content-Type: application/json`:

```json
{
  "name": "museado_items_en",
  "enable_nested_fields": true,
  "default_sorting_field": "date",
  "token_separators": [],
  "symbols_to_index": [],
  "metadata": {
    "schema_version": "1",
    "lang": "en",
    "source": "museado-pipeline",
    "created_at": "2026-03-20T00:00:00Z"
  },
  "fields": [
    {"name": "id",          "type": "string"},
    {"name": "title",       "type": "string",    "locale": "en"},
    {"name": "description", "type": "string",    "locale": "en",   "optional": true},
    {"name": "date",        "type": "int64",     "optional": true, "sort": true},
    {"name": "inst_id",     "type": "string",    "facet": true},
    {"name": "source",      "type": "string",    "facet": true,    "optional": true},
    {"name": "media_type",  "type": "string",    "facet": true,    "optional": true},
    {"name": "subject",     "type": "string[]",  "facet": true,    "optional": true},
    {"name": "creator",     "type": "string*",   "facet": true,    "optional": true},
    {"name": "location",    "type": "geopoint",  "optional": true},
    {"name": "thumbnail_url","type": "string",   "index": false,   "optional": true},
    {"name": "iiif_manifest","type": "string",   "index": false,   "optional": true},
    {"name": "ark",         "type": "string",    "index": false,   "optional": true},
    {"name": ".*_facet",    "type": "string",    "facet": true,    "optional": true},
    {"name": ".*",          "type": "auto",      "index": false,   "optional": true}
  ]
}
```

**Retrieve the live schema at any time:**
```bash
GET /collections/museado_items_en
# Returns full schema including inferred fields, doc count, created_at
```

This is the schema registry. No separate database needed.

---

## Field Parameters Reference

| Parameter | Type | Notes |
|---|---|---|
| `name` | string | Field name or RegEx pattern (e.g. `.*_facet`, `num_.*`) |
| `type` | string | See types table below |
| `facet` | bool | Enables facet counts. Costs RAM — only set what you actually facet on |
| `index` | bool | `false` = stored on disk, returned in hits, no RAM cost. Default: true |
| `optional` | bool | Don't reject docs missing this field. Default: false |
| `sort` | bool | Required for `sort_by` on this field. Default: false |
| `locale` | ISO 639-1 | Tokenization language. Default: `en` (strips diacritics). Others preserve them via ICU |
| `stem` | bool | Word stemming (run/running/ran → same token). Default: false |
| `infix` | bool | Substring search — expensive, off by default |
| `drop` | bool | Used in PATCH to remove a field |
| `embed` | object | Auto-generate vector embeddings from this field's content |
| `reference` | string | Link to another collection's field for JOINs (`other_collection.field`) |
| `range_index` | bool | Optimized index for range filters on numeric fields |
| `num_dim` | int | For `float[]` vector fields — dimensionality |

## Field Types Reference

| Type | Notes |
|---|---|
| `string` | Single string value |
| `string[]` | Array of strings |
| `string*` | **Accepts both scalar and array** — essential for dirty museum data where `creator` is sometimes a string, sometimes an array depending on source |
| `int32` / `int32[]` | 32-bit integer |
| `int64` / `int64[]` | 64-bit integer — use for Unix timestamps, large IDs |
| `float` / `float[]` | Floating point. `float[]` used for vector embeddings |
| `bool` / `bool[]` | Boolean |
| `object` | Nested JSON object (requires `enable_nested_fields: true`) |
| `object[]` | Array of nested objects |
| `geopoint` | `[lat, lng]` float pair — **must be declared explicitly**, cannot be auto-detected |
| `geopoint[]` | Multiple locations per document |
| `auto` | Type inferred from first document — locked in after that, coercion attempted on mismatches |
| `image` | For CLIP-based image search |

**Date/time:** Typesense has no native date type. Store as `int64` Unix timestamp. Filter with
`date:>1700000000`. Most languages have trivial conversion.

---

## RegEx Field Names

Field names are matched as patterns, enabling convention-based schemas:

```json
{"name": ".*_facet",  "type": "string",  "facet": true,  "optional": true}
{"name": "num_.*",    "type": "int32",   "optional": true}
{"name": ".*_url",    "type": "string",  "index": false, "optional": true}
```

Institutions can add `donation_source_facet`, `deed_book_facet`, `condition_facet` — any
`*_facet` field auto-becomes a facet without schema changes. This is the key to
institution-specific metadata without per-institution schema maintenance.

**Precedence:** explicit field names take priority over regex matches, which take priority over
the `.*` wildcard. More specific wins.

---

## How This Connects to the Existing JSONL Pipeline

### Current pipeline (Meilisearch)

```
Source data (Smithsonian API, Europeana OAI-PMH, museum-digital, Fortepan, etc.)
  → Normalization (PHP, ZM data model)
  → Language detection (xml:lang metadata + langdetect fallback)
  → JSONL files per source per language
  → Meilisearch import (async task queue)
  → Meilisearch settings: filterableAttributes, sortableAttributes, searchableAttributes
```

Meilisearch settings are pushed *after* indexing because Meilisearch is schemaless — there is
no upfront contract. Changing settings triggers a full async re-index.

### With Typesense (additive, same normalized docs)

```
Same normalization pipeline
  → Same language detection + routing
  → Same JSONL files (minimal transformation needed)
  → Typesense schema created upfront (schema is the contract)
  → JSONL import — documents validated on arrival
  → Invalid documents reported per-line, don't abort batch
  → Schema changes via PATCH (additive) or alias-swap (breaking)
```

The only JSONL differences from Meilisearch output:

| Field | Meilisearch | Typesense |
|---|---|---|
| Dates | ISO 8601 string OK | Must be `int64` Unix timestamp |
| Geopoint | `{"lat": 48.8, "lng": 2.3}` object OK | Must be `[48.8, 2.3]` float array |
| Arrays vs scalar | Inconsistent across sources is fine (schemaless) | Use `string*` type for fields that vary |
| Extra fields | All indexed automatically | Caught by `.*` wildcard, stored not indexed |

Minimal transformation — a thin adapter on top of existing normalization, not a rewrite.

---

## Schema as Source of Truth (vs Meilisearch)

Meilisearch's settings API (`/indexes/{index}/settings`) controls which fields are
filterable, sortable, and searchable — but these are *post-hoc annotations* on an otherwise
schemaless store. You can't ask Meilisearch "what type is the `date` field?" because it doesn't
know. The settings live separately from the data model, and there's no enforcement.

Typesense schema is a contract declared upfront:

```bash
# Everything about museado_items_en in one call:
GET /collections/museado_items_en

# All collections, with doc counts:
GET /collections

# Inspect what was auto-inferred from your first import batch:
GET /collections/museado_items_en_bootstrap
```

The response includes every field, its type, all flags (`facet`, `sort`, `index`, `optional`),
the metadata you attached, document count, and creation timestamp. This is your schema registry.
No Supabase, no separate config store.

---

## Schema Lifecycle

### Creating
```bash
POST /collections
# Body: full schema JSON above
# Synchronous — collection ready immediately
```

### Updating (live, no downtime for additive changes)
```bash
PATCH /collections/museado_items_en
{
  "fields": [
    {"name": "rights",      "type": "string",  "facet": true, "optional": true},
    {"name": "old_field",   "drop": true}
  ]
}
```

- **Adding optional fields:** immediate, no re-index
- **Changing `facet`/`index`/`sort` flags:** synchronous re-index, blocks until done
- **Type changes:** validated against existing data first; rejected if incompatible
- **Dropping fields:** triggers re-index
- **Check status:** `GET /collections/museado_items_en` — `num_documents` plus any
  ongoing operation details

### Zero-downtime breaking changes (alias pattern)
```bash
# 1. Create new version alongside old
POST /collections  →  museado_items_en_v2

# 2. Reindex into v2 (your existing import pipeline, pointed at new name)

# 3. Atomic alias swap
PUT /aliases/museado_items_en
{"collection_name": "museado_items_en_v2"}

# 4. Drop old version
DELETE /collections/museado_items_en_v1
```

All queries target the alias `museado_items_en` — no frontend changes needed.

### Cloning a schema (for new institution provisioning)
```bash
POST /collections
{
  "name": "new_inst_items_en",
  "clone_collection_name": "museado_items_en"
}
# Copies schema and settings, not documents
```

---

## PHP: Schema-from-Attributes (the `survos/typesense-bundle` design)

The goal: your PHP class *is* the schema definition. Same Attribute-driven pattern as
API Platform, Doctrine, and existing `survos/meili-bundle`.

```php
#[TypesenseCollection(
    baseName: 'items',          // actual name = {baseName}_{lang}, e.g. items_en
    defaultSortingField: 'date',
    enableNestedFields: true,
    metadata: ['schema_version' => '1', 'normalized_from' => 'zm'],
)]
class ItemDocument
{
    // id is always required — use ARK
    public string $id;

    #[TypesenseField(type: 'string', locale: 'en')]
    // locale overridden per-language-collection at provision time
    public string $title;

    #[TypesenseField(type: 'string', optional: true)]
    public ?string $description;

    #[TypesenseField(type: 'int64', optional: true, sort: true)]
    // Stored as Unix timestamp — normalization pipeline handles conversion
    public ?int $date;

    #[TypesenseField(type: 'string', facet: true)]
    public string $inst_id;

    #[TypesenseField(type: 'string', facet: true, optional: true)]
    public ?string $source;

    #[TypesenseField(type: 'string', facet: true, optional: true)]
    public ?string $media_type;

    #[TypesenseField(type: 'string[]', facet: true, optional: true)]
    public array $subject = [];

    #[TypesenseField(type: 'string*', facet: true, optional: true)]
    // string* = accepts scalar OR array — handles inconsistent source data
    public string|array|null $creator;

    #[TypesenseField(type: 'geopoint', optional: true)]
    // Must be explicit — geopoint cannot be auto-detected
    public ?array $location;

    // Stored fields — returned in results, NO RAM cost
    #[TypesenseField(type: 'string', index: false, optional: true)]
    public ?string $thumbnail_url;

    #[TypesenseField(type: 'string', index: false, optional: true)]
    public ?string $iiif_manifest;

    #[TypesenseField(type: 'string', index: false, optional: true)]
    public ?string $ark;

    // Convention-based catch-alls — NOT declared as class properties
    // Added to schema by the bundle automatically:
    // {"name": ".*_facet", "type": "string", "facet": true, "optional": true}
    // {"name": ".*",       "type": "auto",   "index": false, "optional": true}
}
```

### Console commands to implement

```bash
# Show schema diff between PHP class and live collection
php bin/console typesense:schema:diff ItemDocument --lang=en

# Push schema (create if not exists, PATCH if exists, report breaking changes)
php bin/console typesense:schema:sync ItemDocument --lang=en
php bin/console typesense:schema:sync --all

# Provision a new institution (create all collections for all content types + languages)
php bin/console typesense:provision --inst=fauquier-historical

# Bootstrap schema from existing JSONL (auto-detect then export as PHP Attributes stub)
php bin/console typesense:schema:bootstrap items_en_bootstrap --output=ItemDocument.php

# Import JSONL (thin wrapper around bulk import API)
php bin/console typesense:import items_en --file=museado_smithsonian_en.jsonl --action=upsert
```

---

## Language-Per-Collection

```
museado_items_en    museado_items_es    museado_items_de    museado_items_fr
museado_items_pt    museado_items_nl    museado_items_zh    ...
```

- Schema is identical across language collections; `locale` on text fields changes
- The provisioning command loops over configured languages, substituting locale
- Existing pipeline's language detection + routing applies unchanged
- Federated cross-language search = single `POST /multi_search` call

**What about documents with no translation?**

Options (decide before coding):
1. English-only: item only appears in `_en` index (simplest)
2. Duplicate to all indexes with `translated: false` field for filtering
3. Frontend fallback: query `_en` when target language returns zero results

---

## Comparison: Meilisearch Settings vs Typesense Schema

| Concern | Meilisearch | Typesense |
|---|---|---|
| Schema definition | None — schemaless | Upfront, enforced |
| Type checking | None | Strict by default; coerce or reject on mismatch |
| Facets declared | `filterableAttributes` (post-hoc) | `facet: true` on field (upfront) |
| Sort fields | `sortableAttributes` (post-hoc) | `sort: true` on field (upfront) |
| Full-text fields | `searchableAttributes` (post-hoc) | All indexed fields are searchable by default |
| Schema introspection | `GET /indexes/{name}/settings` — behavior flags only, no types | `GET /collections/{name}` — full schema with types |
| Schema as registry | No — need external store | Yes — lives in Typesense itself |
| Field type | Inferred, not queryable | Declared and queryable |
| Change cost | Any settings change = full async re-index | Additive changes free; structural changes re-index |
| Unindexed storage | Not possible to exclude fields from index | `index: false` = disk only, no RAM |

The Meilisearch workflow is: normalize → import → configure settings.
The Typesense workflow is: design schema → configure → normalize → import.
The payoff is type safety, coherent facets, and no external schema registry.

---

## Open Questions / TODO

- [ ] Decide `string*` vs `string[]` for `creator` field — `string*` is permissive but less
  predictable; `string[]` forces normalization to always produce arrays (probably better)
- [ ] Confirm geopoint data in existing normalized docs — is it already `[lat, lng]` array
  or is it an object that needs conversion?
- [ ] Date field: audit normalized docs for ISO string vs timestamp — add conversion step
  to Typesense adapter if not already int64
- [ ] Decide cross-language fallback behavior (see options above)
- [ ] Schema versioning: store `schema_version` in `metadata`, increment on breaking changes
- [ ] `acseo/typesense-bundle` vs SEAL vs custom `survos/typesense-bundle` — the Attribute
  approach above requires custom; decide if it's worth it vs forking `acseo`
- [ ] Benchmark: at Museado scale (millions of docs, 8+ languages), does Typesense's
  in-memory model require vertical scaling beyond current Hetzner nodes?
- [ ] Token separators: does the normalization pipeline strip hyphens, slashes, etc. from
  identifiers like call numbers (`E 185.61 .N37`) or should `token_separators` handle it?

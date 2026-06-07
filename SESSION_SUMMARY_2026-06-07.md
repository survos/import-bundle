# Session Summary: Progress Bars and Dataset Raw/Vault Model

Date: 2026-06-07

## Main Outcomes

- Added Symfony 8.1-style progress reporting to `import:convert`.
- Added progress reporting patterns for `folio:build` and `dataset:fetch:*`.
- Improved AUST resume behavior using JSONL sidecar context, so appending can resume by offset/page state rather than restarting.
- Clarified the new dataset storage vocabulary:
  - `vault/` is durable and shippable.
  - `work/` is disposable.
  - `_raw/` is a local source view or override, not the source of truth.
  - materialized raw JSONL artifacts belong in `vault/<provider>/<code>/obj.jsonl[.gz]`.
  - legacy flat vault archives like `vault/<provider>/<code>.jsonl.gz` remain readable as fallback.

## Import Bundle

Touched:

- `src/Command/ImportConvertCommand.php`
- `composer.json`

Changes:

- `import:convert` now starts a Symfony 8.1 progress bar when converting dataset JSONL.
- Uses sidecar row counts when available, with ETA.
- Falls back to an indeterminate progress format when only stream progress is possible.
- Composer Symfony constraints were moved to Symfony 8.1-only for this work.

Verification:

- `php -l src/Command/ImportConvertCommand.php` passed.
- `composer validate` passed.
- `git diff --check -- src/Command/ImportConvertCommand.php composer.json` passed.
- Targeted phpunit run was attempted, but failed on pre-existing test/autoload issues unrelated to the progress change.

## Folio Bundle

Touched:

- `/home/tac/sites/mono/bu/folio-bundle/src/Service/FolioIngestService.php`

Changes:

- Replaced manual `ProgressBar` setup with Symfony 8.1 `SymfonyStyle::progressStart()`, `progressAdvance()`, and `progressFinish()`.
- Progress now spans cores, terms, and links using sidecar-aware counts.

Verification:

- PHP syntax check passed.
- Diff whitespace check passed.

## MD App: Dataset Fetch Progress

Touched:

- `/home/tac/sites/md/src/Fetch/DatasetFetchProgress.php`
- `/home/tac/sites/md/src/Aggregator/Progress/SymfonyProgressReporter.php`
- `/home/tac/sites/md/src/Singleton/Aust.php`
- `/home/tac/sites/md/src/Singleton/Smk.php`
- `/home/tac/sites/md/src/Singleton/Victoria.php`
- `/home/tac/sites/md/src/Singleton/Belvedere.php`
- `/home/tac/sites/md/src/Singleton/Larco.php`

Changes:

- Added `DatasetFetchProgress` helper for records/pages/bytes progress bars.
- Updated singleton fetchers to use the helper instead of direct progress bar handling.
- Updated existing progress reporter to Symfony 8.1 style.
- AUST now stores resume state in JSONL sidecar context while appending:
  - `provider`
  - `nextOffset`
  - `perPage`
  - `includeNonCc`
  - `sourceObjects`
  - `counts`
- AUST resumes from sidecar context when compatible with the current options.

Verification:

- PHP syntax checks passed for all touched MD progress/fetch PHP files.

## DC and Smithsonian Raw/Vault Model

Touched:

- `/home/tac/sites/md/src/Command/DcGlobalSplitCommand.php`
- `/home/tac/sites/md/src/Workflow/SmithWorkflow.php`
- `/home/tac/sites/md/docs/smith-ingest-plan.md`
- `/home/tac/sites/mono/bu/dataset-bundle/src/Service/DataPaths.php`
- `/home/tac/sites/mono/bu/dataset-bundle/src/Service/SurvosDatasetPathsFactory.php`

Changes:

- Confirmed `DataPaths` already resolves raw files in the intended order:
  1. `work/<provider>/<code>/_raw/obj.jsonl`
  2. `work/<provider>/<code>/_raw/obj.jsonl.gz`
  3. `vault/<provider>/<code>/obj.jsonl.gz`
  4. `vault/<provider>/<code>/obj.jsonl`
  5. legacy `vault/<provider>/<code>.jsonl.gz`
  6. legacy `vault/<provider>/<code>.jsonl`
- DC archive generation now writes canonical nested raw artifacts:
  - `vault/dc/<code>/obj.jsonl.gz`
- DC manifest `archiveFile` now records:
  - `<code>/obj.jsonl.gz`
- DC generated README text now says normalization reads the resolved raw artifact, not a required symlink.
- Smithsonian workflow now prefers:
  - `vault/smith/<unit>/obj.jsonl.gz`
  - with fallback to `vault/smith/<unit>.jsonl.gz`
- Removed suppressed `@gzopen()` in the touched Smithsonian workflow.
- Cleaned stale numeric-stage comments in dataset-bundle to `_raw`, `norm`, and `voc`.

Verification:

- `php -l` passed for:
  - `/home/tac/sites/md/src/Command/DcGlobalSplitCommand.php`
  - `/home/tac/sites/md/src/Workflow/SmithWorkflow.php`
  - `/home/tac/sites/mono/bu/dataset-bundle/src/Service/DataPaths.php`
  - `/home/tac/sites/mono/bu/dataset-bundle/src/Service/SurvosDatasetPathsFactory.php`
- `git diff --check` passed for scoped MD and dataset-bundle changes.

## Vocabulary Settled

- `vault`: durable acquired source plus expensive/reusable materialized raw.
- `cache`: bulky re-fetchable intermediates.
- `work`: disposable pipeline output.
- `_raw`: local source view or override, often a portal to vault, but not the durable truth.
- `norm`: normalized canonical records.
- `voc`: extracted vocabulary.
- `_folio`: assembled folio input.
- `folio`: generated SQLite folio databases.

## Important Notes

- `agg:iterate --tag=symlink` still exists for compatibility, but symlinks should not be the conceptual contract.
- The model should prefer resolver-based raw lookup over forcing every provider to materialize `_raw` links.
- Existing legacy flat archives remain readable, especially for already-published DC/Smithsonian data.
- There were unrelated dirty files in `/home/tac/sites/md`; they were left untouched.

## Follow-Ups

- Decide whether to rename or de-emphasize `--tag=symlink`, perhaps to `raw-view` or `materialize-raw-view`.
- Update any remaining docs that still say `05_raw` or `archive/` as canonical.
- Consider migrating other archive-first providers, such as MDS and musdig, to nested `vault/<provider>/<code>/obj.jsonl.gz`.
- Consider moving DC `_listing-pages` under a clearer provider-level vault/cache path, since they are expensive to fetch but not direct normalized raw.
- Run broader tests once the pre-existing md/import-bundle unrelated failures are cleared.

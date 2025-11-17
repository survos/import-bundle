<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\PropertyInfo\DoctrineExtractor;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\String\UnicodeString;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeIdentifier;

/**
 * Best-effort array/stdClass → object mapper (Symfony 7.3 / PHP 8.4).
 *
 * - Keys normalized to snake_case, then denormalized to camelCase for writes.
 * - Types resolved via DoctrineExtractor::getType() (TypeInfo) for coercions.
 * - Unknown/unwritable fields are skipped; ignored fields (e.g. PK) are never written.
 *
 * Context options (mapInto $context):
 * - list_delimiters: array<string,string>   Per-field preferred delimiters (fallback: '|' then ',')
 * - coerce_scalars:  bool                   Default true
 * - coerce_dates:    bool                   Default true
 * - null_literals:   string[]               Default ['null','n/a','na','nil','none','']
 * - wrap_scalar_to_array: bool              If expecting array but got scalar, wrap into [scalar] (default false)
 * - debug:           bool                   If true, emit error_log debug lines
 */
final class LooseObjectMapper
{
    private DoctrineExtractor $doctrineExtractor;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?PropertyAccessorInterface $pa = null,
        private ?CamelCaseToSnakeCaseNameConverter $nc = null,
    ) {
        $this->pa = $pa ?? PropertyAccess::createPropertyAccessorBuilder()
            ->enableExceptionOnInvalidIndex()
            ->getPropertyAccessor();

        $this->doctrineExtractor = new DoctrineExtractor($this->entityManager);
        $this->nc = $nc ?? new CamelCaseToSnakeCaseNameConverter();
    }

    /**
     * Map payload into a NEW instance of $class.
     *
     * @param array|object $data
     * @param class-string $class
     */
    public function map(array|object $data, string $class, array $context = []): object
    {
        $object = new $class();
        return $this->mapInto($data, $object, ignored: $context['ignored'] ?? ['id'], context: $context);
    }

    /**
     * Populate an EXISTING object. Primary key and any $ignored fields are untouched.
     *
     * @param array{list_delimiters?:array<string,string>,coerce_scalars?:bool,coerce_dates?:bool,null_literals?:string[],wrap_scalar_to_array?:bool,debug?:bool} $context
     */
    public function mapInto(array|object $data, object $object, array $ignored = ['id'], array $context = []): object
    {
        $debug = (bool)($context['debug'] ?? false);

        $this->debug('mapInto() start', $debug, [
            'object_class' => $object::class,
            'ignored'      => $ignored,
            'raw_keys'     => is_array($data) ? array_keys($data) : array_keys((array)$data),
        ]);

        $arr = is_object($data) ? (array) $data : $data;
        $arr = $this->normalizeKeysToSnake($arr, $debug);

        $coerceScalars   = $context['coerce_scalars'] ?? true;
        $coerceDates     = $context['coerce_dates'] ?? true;
        $perDelim        = $context['list_delimiters'] ?? [];
        $nullLiterals    = array_map('strtolower', $context['null_literals'] ?? ['null','n/a','na','nil','none','']);
        $wrapScalarArray = (bool)($context['wrap_scalar_to_array'] ?? false);

        foreach ($arr as $snakeKey => $value) {
            $prop = $this->nc->denormalize((string) $snakeKey);

            $this->debug('Field loop', $debug, [
                'snake_key' => $snakeKey,
                'initial_prop' => $prop,
                'ignored_list' => $ignored,
            ]);

            if (in_array($prop, $ignored, true)) {
                $this->debug('Skipping ignored field', $debug, ['prop' => $prop]);
                continue;
            }

            $writable = $this->pa->isWritable($object, $prop);
            $this->debug('Writable check', $debug, [
                'prop'      => $prop,
                'writable'  => $writable,
                'class'     => $object::class,
            ]);

            // Try alternative acronym property if not writable (Id → ID, Url → URL, etc.)
            if (!$writable) {
                $altProp = $this->resolveAcronymProperty($object, $prop, $debug);

                if ($altProp === null) {
                    $this->debug('No writable property found, skipping field', $debug, [
                        'snake_key' => $snakeKey,
                        'prop'      => $prop,
                    ]);
                    continue;
                }

                $this->debug('Using altProp for field', $debug, [
                    'snake_key' => $snakeKey,
                    'prop'      => $prop,
                    'alt_prop'  => $altProp,
                ]);

                $prop = $altProp;
            }

            // Resolve destination type via new TypeInfo-aware API.
            $type = $this->doctrineExtractor->getType($object::class, $prop);

            if ($type instanceof Type) {
                $this->debug('TypeInfo resolved', $debug, [
                    'prop'      => $prop,
                    'type'      => (string)$type,
                    'value_raw' => $this->shortExport($value),
                ]);

                $value = $this->coerceValueForType(
                    value: $value,
                    type: $type,
                    field: $prop,
                    perFieldDelimiter: $perDelim,
                    coerceScalars: $coerceScalars,
                    coerceDates: $coerceDates,
                    nullLiterals: $nullLiterals,
                    wrapScalarArray: $wrapScalarArray,
                    debug: $debug
                );
            } else {
                $this->debug('No TypeInfo for property (non-Doctrine field?)', $debug, [
                    'prop'      => $prop,
                    'value_raw' => $this->shortExport($value),
                ]);
            }

            try {
                $this->pa->setValue($object, $prop, $value);
                $this->debug('setValue() OK', $debug, [
                    'prop'      => $prop,
                    'value_set' => $this->shortExport($value),
                ]);
            } catch (\Throwable $e) {
                $this->debug('setValue() FAILED', $debug, [
                    'prop'   => $prop,
                    'error'  => $e->getMessage(),
                    'class'  => $object::class,
                ]);
                // best-effort: skip unassignable fields
            }
        }

        $this->debug('mapInto() done', $debug, [
            'object_class' => $object::class,
        ]);

        return $object;
    }

    /**
     * Coerce $value to fit $type:
     * - arrays from delimited strings OR JSON-in-a-CSV (["a","b"] or ['a','b'])
     * - ints/floats/bools (with extra 'is/has'* handling)
     * - DateTime via ISO, US m/d/y, or epoch seconds
     * - "null"/"n/a" → null
     */
    private function coerceValueForType(
        mixed $value,
        Type $type,
        string $field,
        array $perFieldDelimiter,
        bool $coerceScalars,
        bool $coerceDates,
        array $nullLiterals,
        bool $wrapScalarArray,
        bool $debug
    ): mixed {
        $this->debug('coerceValueForType() start', $debug, [
            'field' => $field,
            'type'  => (string)$type,
            'value_raw' => $this->shortExport($value),
        ]);

        // Normalize empties & common null-literals
        if ($value === null) {
            $this->debug('Value is already null', $debug, ['field' => $field]);
            return null;
        }
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '' || in_array(strtolower($trim), $nullLiterals, true)) {
                $this->debug('String matched null literal', $debug, [
                    'field' => $field,
                    'raw'   => $value,
                ]);
                return null;
            }
            $value = $trim; // keep a trimmed working value
        }

        // ARRAY expectations
        if ($type->isIdentifiedBy(TypeIdentifier::ARRAY)) {
            // 1) JSON array embedded as string: e.g. ["a","b"] or ['a','b']
            if (is_string($value) && $this->looksLikeJsonArray($value)) {
                $decoded = $this->decodeJsonArray($value);
                if (is_array($decoded)) {
                    $this->debug('Array coercion via JSON decode', $debug, [
                        'field' => $field,
                        'result' => $this->shortExport($decoded),
                    ]);
                    return $decoded;
                }
            }

            // 2) Delimited string → array
            if (is_string($value)) {
                $delim = $perFieldDelimiter[$field]
                    ?? (str_contains($value, '|') ? '|' : (str_contains($value, ',') ? ',' : '|'));

                $parts = array_map('trim', explode($delim, $value));
                $result = array_values(array_filter($parts, static fn($s) => $s !== ''));

                $this->debug('Array coercion via delimiter', $debug, [
                    'field'    => $field,
                    'delim'    => $delim,
                    'result'   => $this->shortExport($result),
                ]);

                return $result;
            }

            // 3) Optionally wrap scalars to array
            if ($wrapScalarArray && !is_array($value)) {
                $this->debug('Array coercion via wrapScalarArray', $debug, [
                    'field'  => $field,
                    'result' => $this->shortExport([$value]),
                ]);
                return [$value];
            }

            return $value;
        }

        // BOOL expectations
        if ($type->isIdentifiedBy(TypeIdentifier::BOOL)) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value)) {
                $low = strtolower($value);
                if (in_array($low, ['true','false'], true)) {
                    return $low === 'true';
                }
                if (in_array($low, ['yes','y','on','1'], true)) {
                    return true;
                }
                if (in_array($low, ['no','n','off','0'], true)) {
                    return false;
                }
            }
            if (is_int($value)) {
                return $value === 1;
            }
            if (is_float($value)) {
                return (int)$value === 1;
            }
        }

        // Heuristic: fields that start with "is" or "has" can coerce 1/0 to bool safely
        if ($this->looksBooleanField($field)) {
            if (is_string($value) && ($value === '1' || $value === '0')) {
                return $value === '1';
            }
            if (is_int($value) || is_float($value)) {
                return (int)$value === 1;
            }
        }

        // INT expectations
        if ($type->isIdentifiedBy(TypeIdentifier::INT) && $coerceScalars) {
            if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
                $hasLeadingZero = strlen($value) > 1 && $value[0] === '0';
                if (!$hasLeadingZero || $this->looksNumericField($field)) {
                    return (int) $value;
                }
            }
            if (is_float($value)) {
                return (int) $value;
            }
        }

        // FLOAT expectations
        if ($type->isIdentifiedBy(TypeIdentifier::FLOAT) && $coerceScalars) {
            if (is_string($value) && is_numeric($value)) {
                return (float) $value;
            }
            if (is_int($value)) {
                return (float) $value;
            }
        }

        // OBJECT expectations → DateTimeInterface coercion (safe default to DateTimeImmutable)
        if ($coerceDates && $type->isIdentifiedBy(TypeIdentifier::OBJECT)) {
            // attempt to parse datetimes commonly found in imports
            if (is_string($value)) {
                // ISO 8601
                if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/', $value)) {
                    try { return new DateTimeImmutable($value); } catch (\Throwable) {}
                }
                // YYYY-MM-DD (date only)
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    try { return new DateTimeImmutable($value . 'T00:00:00Z'); } catch (\Throwable) {}
                }
                // US m/d/yy or m/d/yyyy
                if (preg_match('#^\d{1,2}/\d{1,2}/\d{2,4}$#', $value)) {
                    [$m,$d,$y] = array_map('intval', explode('/', $value));
                    if ($y < 100) { $y += ($y >= 70 ? 1900 : 2000); }
                    $iso = sprintf('%04d-%02d-%02dT00:00:00Z', $y, $m, $d);
                    try { return new DateTimeImmutable($iso); } catch (\Throwable) {}
                }
                // Unix epoch seconds (10 digits) or milliseconds (13 digits)
                if (preg_match('/^\d{10}(\d{3})?$/', $value)) {
                    $ts = (int) (strlen($value) === 13 ? substr($value, 0, 10) : $value);
                    try { return (new DateTimeImmutable())->setTimestamp($ts); } catch (\Throwable) {}
                }
            }
            if (is_int($value)) {
                try { return (new DateTimeImmutable())->setTimestamp($value); } catch (\Throwable) {}
            }
            if (is_float($value)) {
                try { return (new DateTimeImmutable())->setTimestamp((int)$value); } catch (\Throwable) {}
            }
        }

        // JSON object embedded as string (leave arrays for the ARRAY branch above)
        if (is_string($value) && $this->looksLikeJsonObject($value)) {
            $decoded = json_decode($this->normalizeJsonQuotes($value), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->debug('Object coercion via JSON decode', $debug, [
                    'field'  => $field,
                    'result' => $this->shortExport($decoded),
                ]);
                return $decoded;
            }
        }

        $this->debug('coerceValueForType() end (no change)', $debug, [
            'field' => $field,
            'final' => $this->shortExport($value),
        ]);

        return $value;
    }

    private function looksNumericField(string $field): bool
    {
        static $hints = [
            'id','count','index','position','rank','duration','size',
            'budget','revenue','popularity','score','rating','price','quantity',
            'voteCount','voteAverage','runtime','year','page','pages','length','height','width',
        ];
        return in_array($field, $hints, true);
    }

    private function looksBooleanField(string $field): bool
    {
        return str_starts_with($field, 'is') || str_starts_with($field, 'has');
    }

    /**
     * Normalize incoming keys so NameConverter can reliably denormalize them:
     *  - "CREATEDAT" → "createdat"
     *  - "createdAt" → "created_at"
     *  - "created_at" stays "created_at"
     *  - "Identification.ID" → "identification_id"
     *  - "Engine Information.Driveline" → "engine_information_driveline"
     */
    private function normalizeKeysToSnake(array $input, bool $debug = false): array
    {
        $out = [];
        foreach ($input as $k => $v) {
            $normalizedKey = strtr((string) $k, [
                '.' => '_',
                ' ' => '_',
            ]);

            $key = (new UnicodeString($normalizedKey))->snake()->toString();

            $this->debug('normalizeKeysToSnake()', $debug, [
                'original_key'   => (string)$k,
                'normalized_key' => $normalizedKey,
                'snake_key'      => $key,
            ]);

            $out[$key] = is_array($v) ? $this->normalizeKeysToSnake($v, $debug) : $v;
        }
        return $out;
    }

    // --- JSON helpers for CSV-embedded JSON ---

    private function looksLikeJsonArray(string $s): bool
    {
        $s = ltrim($s);
        return $s !== '' && ($s[0] === '[');
    }

    private function looksLikeJsonObject(string $s): bool
    {
        $s = ltrim($s);
        return $s !== '' && ($s[0] === '{');
    }

    /** Accepts single-quoted arrays/objects by swapping to double quotes when safe. */
    private function normalizeJsonQuotes(string $s): string
    {
        // quick path: already valid JSON
        json_decode($s);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $s;
        }
        // allow very common CSV form: ['a','b'] or {'k':'v'}
        // naive but effective: replace single quotes with double if we appear to have a JSON-ish structure
        if (preg_match('/^\s*[\[\{].*[\]\}]\s*$/s', $s)) {
            return preg_replace("/'/", '"', $s) ?? $s;
        }
        return $s;
    }

    private function decodeJsonArray(string $s): ?array
    {
        $norm = $this->normalizeJsonQuotes($s);
        $decoded = json_decode($norm, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
    }

    // --- Convenience name transforms & row key resolution ---

    /** Convert field name to snake_case (id => id, accessionNumber => accession_number). */
    public function toSnake(string $name): string
    {
        return (new UnicodeString($name))->snake()->toString();
    }

    /** Convert field name (snake/kebab) to lowerCamelCase (accession_number => accessionNumber). */
    public function toCamel(string $name): string
    {
        $u = new UnicodeString(str_replace('-', '_', $name));
        $parts = array_map(
            fn ($p) => (new UnicodeString($p))->title()->toString(),
            array_filter(explode('_', (string) $u))
        );
        return lcfirst(implode('', $parts));
    }

    /**
     * Generate likely CSV/array key candidates for a given entity field.
     * Order matters: exact, snake, camel, then lowercase fallbacks.
     */
    public function keyCandidates(string $field): array
    {
        $snake = $this->toSnake($field);
        $camel = $this->toCamel($field);

        $cands = [
            $field,
            $snake,
            $camel,
            strtolower($field),
            strtolower($snake),
            strtolower($camel),
        ];

        // de-dup while preserving order
        $seen = [];
        $out = [];
        foreach ($cands as $c) {
            if (!isset($seen[$c])) {
                $seen[$c] = true;
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * Find the actual row key for an entity field, tolerating snake/camel, dots/spaces and case.
     * Returns the original key from $row, or null if not found.
     */
    public function resolveRowKey(array $row, string $field, bool $debug = false): ?string
    {
        $this->debug('resolveRowKey() start', $debug, [
            'field'   => $field,
            'rowKeys' => array_keys($row),
        ]);

        // 1) Exact candidates (as-is)
        foreach ($this->keyCandidates($field) as $cand) {
            if (array_key_exists($cand, $row)) {
                $this->debug('resolveRowKey(): direct candidate hit', $debug, [
                    'field'    => $field,
                    'candidate'=> $cand,
                ]);
                return $cand;
            }
        }

        // 2) Case-insensitive candidates
        $lowerMap = array_change_key_case($row, CASE_LOWER);
        foreach ($this->keyCandidates($field) as $cand) {
            $lc = strtolower($cand);
            if (array_key_exists($lc, $lowerMap)) {
                foreach ($row as $orig => $_) {
                    if (strtolower((string)$orig) === $lc) {
                        $this->debug('resolveRowKey(): case-insensitive hit', $debug, [
                            'field' => $field,
                            'orig'  => $orig,
                            'lc'    => $lc,
                        ]);
                        return $orig;
                    }
                }
            }
        }

        // 3) Canonical “squashed” match: handles Identification.ID vs identificationID, etc.
        $targetCanonical = $this->canonicalKey($field);
        if ($targetCanonical !== '') {
            foreach ($row as $orig => $_) {
                if ($this->canonicalKey((string)$orig) === $targetCanonical) {
                    $this->debug('resolveRowKey(): canonical hit', $debug, [
                        'field'    => $field,
                        'orig'     => $orig,
                        'canonical'=> $targetCanonical,
                    ]);
                    return (string)$orig;
                }
            }
        }

        $this->debug('resolveRowKey(): no match', $debug, [
            'field' => $field,
        ]);

        return null;
    }

    private function canonicalKey(string $key): string
    {
        // "identificationID"              → "identificationid"
        // "Identification.ID"             → "identificationid"
        // "Engine Information.Driveline"  → "engineinformationdriveline"
        return preg_replace('/[^a-z0-9]+/', '', strtolower($key)) ?: '';
    }

    /**
     * Handle common acronym casing: Id → ID, Url → URL, etc.
     */
    private function resolveAcronymProperty(object $object, string $prop, bool $debug): ?string
    {
        // IdentificationId → IdentificationID
        if (str_ends_with($prop, 'Id')) {
            $alt = substr($prop, 0, -2) . 'ID';
            $writable = $this->pa->isWritable($object, $alt);

            $this->debug('resolveAcronymProperty() Id→ID heuristic', $debug, [
                'prop'     => $prop,
                'alt_prop' => $alt,
                'writable' => $writable,
            ]);

            if ($writable) {
                return $alt;
            }
        }

        // Future: more acronym rules (Url→URL, Api→API, etc.) if needed.

        return null;
    }

    // --- Debug helpers ---

    private function debug(string $msg, bool $enabled, array $context = []): void
    {
        if (!$enabled) {
            return;
        }

        $suffix = '';
        if ($context !== []) {
            $suffix = ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        error_log('[LooseObjectMapper] ' . $msg . $suffix);
    }

    private function shortExport(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) var_export($value, true);
        }
        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }
        if (is_object($value)) {
            return 'object(' . $value::class . ')';
        }

        return get_debug_type($value);
    }
}


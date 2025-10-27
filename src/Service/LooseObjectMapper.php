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
 * - Keys are normalized to snake_case, then denormalized back to camelCase via NameConverter.
 * - Types resolved via DoctrineExtractor::getType() (TypeInfo) for smart coercions.
 * - Unknown/unwritable fields are skipped; ignored fields (e.g. PK) are never written.
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
     * Context options:
     * - list_delimiters: ['cast' => '|', 'genres' => '|']  // per-field preferred delimiters
     * - coerce_scalars:  true|false (default true)
     * - coerce_dates:    true|false (default true)
     */
    public function mapInto(array|object $data, object $object, array $ignored = ['id'], array $context = []): object
    {
        $arr = is_object($data) ? (array) $data : $data;
        $arr = $this->normalizeKeysToSnake($arr);

        $coerceScalars = $context['coerce_scalars'] ?? true;
        $coerceDates   = $context['coerce_dates']   ?? true;
        $perDelim      = $context['list_delimiters'] ?? [];

        foreach ($arr as $snakeKey => $value) {
            // Convert incoming key to the object's property name.
            $prop = $this->nc->denormalize((string) $snakeKey);

            if (in_array($prop, $ignored, true)) {
                continue;
            }
            if (!$this->pa->isWritable($object, $prop)) {
                continue;
            }

            // Resolve destination type via new TypeInfo-aware API.
            $type = $this->doctrineExtractor->getType($object::class, $prop);

            if ($type instanceof Type) {
                $value = $this->coerceValueForType(
                    value: $value,
                    type: $type,
                    field: $prop,
                    perFieldDelimiter: $perDelim,
                    coerceScalars: $coerceScalars,
                    coerceDates: $coerceDates
                );
            }

            try {
                $this->pa->setValue($object, $prop, $value);
            } catch (\Throwable) {
                // best-effort: skip unassignable fields
            }
        }

        return $object;
    }

    /**
     * Coerce $value to fit $type (arrays from delimited strings, ints/floats/bools, common date formats).
     */
    private function coerceValueForType(
        mixed $value,
        Type $type,
        string $field,
        array $perFieldDelimiter,
        bool $coerceScalars,
        bool $coerceDates
    ): mixed {
        if ($value === '' || $value === null) {
            return null;
        }

        // Arrays: split delimited strings.
        if ($type->isIdentifiedBy(TypeIdentifier::ARRAY) && is_string($value)) {
            $delim = $perFieldDelimiter[$field]
                ?? (str_contains($value, '|') ? '|' : (str_contains($value, ',') ? ',' : '|'));

            $parts = array_map('trim', explode($delim, $value));
            return array_values(array_filter($parts, static fn($s) => $s !== ''));
        }

        if ($coerceScalars && is_string($value)) {
            $v = trim($value);

            // ints
            if ($type->isIdentifiedBy(TypeIdentifier::INT)) {
                if (preg_match('/^-?\d+$/', $v)) {
                    $hasLeadingZero = strlen($v) > 1 && $v[0] === '0';
                    if (!$hasLeadingZero || $this->looksNumericField($field)) {
                        return (int) $v;
                    }
                }
            }

            // floats
            if ($type->isIdentifiedBy(TypeIdentifier::FLOAT) && is_numeric($v)) {
                return (float) $v;
            }

            // bools
            if ($type->isIdentifiedBy(TypeIdentifier::BOOL)) {
                $low = strtolower($v);
                if (in_array($low, ['true','false','yes','no','y','n','on','off','1','0'], true)) {
                    return in_array($low, ['true','yes','y','on','1'], true);
                }
            }

            // dates (only attempt when destination is object-ish)
            if ($coerceDates && $type->isIdentifiedBy(TypeIdentifier::OBJECT)) {
                // ISO 8601
                if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/', $v)) {
                    try { return new DateTimeImmutable($v); } catch (\Throwable) {}
                }
                // m/d/yy or m/d/yyyy
                if (preg_match('#^\d{1,2}/\d{1,2}/\d{2,4}$#', $v)) {
                    [$m,$d,$y] = array_map('intval', explode('/', $v));
                    if ($y < 100) { $y += ($y >= 70 ? 1900 : 2000); }
                    $iso = sprintf('%04d-%02d-%02dT00:00:00Z', $y, $m, $d);
                    try { return new DateTimeImmutable($iso); } catch (\Throwable) {}
                }
            }
        }

        return $value;
    }

    private function looksNumericField(string $field): bool
    {
        static $hints = [
            'id','count','index','position','rank','duration','size',
            'budget','revenue','popularity','score','rating','price','quantity',
            'voteCount','voteAverage','runtime','year',
        ];
        return in_array($field, $hints, true);
    }

    /**
     * Normalize incoming keys so NameConverter can reliably denormalize them:
     *  - "CREATEDAT" → "createdat"
     *  - "createdAt" → "created_at"
     *  - "created_at" stays "created_at"
     */
    private function normalizeKeysToSnake(array $input): array
    {
        $out = [];
        foreach ($input as $k => $v) {
            $key = (new UnicodeString((string) $k))->snake()->toString();
            $out[$key] = is_array($v) ? $this->normalizeKeysToSnake($v) : $v;
        }
        return $out;
    }

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
     * Find the actual row key for an entity field, tolerating snake/camel and case.
     * Returns the original key from $row, or null if not found.
     */
    public function resolveRowKey(array $row, string $field): ?string
    {
        foreach ($this->keyCandidates($field) as $cand) {
            if (array_key_exists($cand, $row)) {
                return $cand;
            }
        }

        $lowerMap = array_change_key_case($row, CASE_LOWER);
        foreach ($this->keyCandidates($field) as $cand) {
            $lc = strtolower($cand);
            if (array_key_exists($lc, $lowerMap)) {
                foreach ($row as $orig => $_) {
                    if (strtolower($orig) === $lc) {
                        return $orig;
                    }
                }
            }
        }

        return null;
    }
}

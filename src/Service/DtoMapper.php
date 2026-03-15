<?php

declare(strict_types=1);

namespace Survos\ImportBundle\Service;

use Survos\ImportBundle\Dto\Attributes\Map as MapAttr;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Reflection-based mapper: populates a DTO from a flat record array.
 *
 * Each public property is resolved in order:
 *   1. #[Map(source: 'key')] — explicit source key
 *   2. #[Map(regex: '/pattern/')] — first matching key
 *   3. Default: try the exact property name as the key
 *
 * After mapping, if the DTO implements afterMap(array $mapped, array $original)
 * that method is called so the DTO can compute derived fields.
 *
 * Context options:
 *   'pixie' => string   Used with #[Map(when:[], except:[])] to activate/skip per-dataset rules.
 */
final class DtoMapper
{
    /**
     * @template T of object
     * @param array<string,mixed> $record
     * @param class-string<T>     $dtoClass
     * @param array{pixie?:string} $context
     * @return T
     */
    public function mapRecord(array $record, string $dtoClass, array $context = []): object
    {
        $rc  = new ReflectionClass($dtoClass);
        $dto = $rc->newInstanceWithoutConstructor();

        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $this->resolveValue($prop, $record, $context);
            $value = $this->coerceToPropertyType($prop, $value);
            if ($value !== null || $this->isNullable($prop)) {
                $prop->setValue($dto, $value);
            }
        }

        if (method_exists($dto, 'afterMap')) {
            $mapped = $this->toArray($dto);
            $dto->afterMap($mapped, $record);
            $this->applyArray($dto, $mapped);
        }

        return $dto;
    }

    /** @return array<string,mixed> */
    public function toArray(object $dto): array
    {
        $out = [];
        $rc  = new ReflectionClass($dto);
        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->isInitialized($dto)) {
                $out[$p->getName()] = $p->getValue($dto);
            }
        }
        return $out;
    }

    private function applyArray(object $dto, array $data): void
    {
        $rc = new ReflectionClass($dto);
        foreach ($data as $k => $v) {
            if ($rc->hasProperty($k)) {
                $p = $rc->getProperty($k);
                if ($p->isPublic() && !$p->isReadOnly()) {
                    $p->setValue($dto, $v);
                }
            }
        }
    }

    private function resolveValue(ReflectionProperty $prop, array $record, array $context): mixed
    {
        $map = $this->getAttribute($prop, MapAttr::class);

        if ($map) {
            // Respect when/except context guards
            $pixie = $context['pixie'] ?? null;
            if ($pixie && $map->when && !in_array($pixie, $map->when, true)) {
                return null;
            }
            if ($pixie && $map->except && in_array($pixie, $map->except, true)) {
                return null;
            }
        }

        $val = null;

        if ($map) {
            if ($map->source !== null && array_key_exists($map->source, $record)) {
                $val = $record[$map->source];
            } elseif ($map->regex !== null) {
                $val = $this->findByRegex($record, $map->regex);
            } elseif (array_key_exists($prop->getName(), $record)) {
                $val = $record[$prop->getName()];
            }

            if ($map->if === 'isset' && $val === null) {
                return null;
            }

            if (is_string($val) && $map->delim && $this->isArrayProperty($prop)) {
                $parts = preg_split('/\s*' . preg_quote($map->delim, '/') . '\s*/', $val) ?: [];
                $val   = array_values(array_filter(array_map('trim', $parts), static fn($s) => $s !== ''));
            }
        } else {
            // No #[Map] — fall back to matching by property name
            if (array_key_exists($prop->getName(), $record)) {
                $val = $record[$prop->getName()];
            }
        }

        return $val;
    }

    private function findByRegex(array $record, string $regex): mixed
    {
        $pattern = str_starts_with($regex, '/') ? $regex : '/' . $regex . '/i';
        foreach ($record as $k => $v) {
            if (@preg_match($pattern, (string) $k)) {
                return $v;
            }
        }
        return null;
    }

    private function isArrayProperty(ReflectionProperty $prop): bool
    {
        $t = $prop->getType();
        return $t instanceof ReflectionNamedType && $t->getName() === 'array';
    }

    private function isNullable(ReflectionProperty $prop): bool
    {
        $t = $prop->getType();
        return $t instanceof ReflectionNamedType && $t->allowsNull();
    }

    private function coerceToPropertyType(ReflectionProperty $prop, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $t = $prop->getType();
        if (!$t instanceof ReflectionNamedType) {
            return $value;
        }

        return match ($t->getName()) {
            'string' => is_string($value) ? $value : (is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)),
            'int'    => is_int($value) ? $value : (is_numeric($value) ? (int) $value : null),
            'float'  => is_float($value) ? $value : (is_numeric($value) ? (float) $value : null),
            'bool'   => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'array'  => is_array($value) ? $value : (is_string($value) ? [$value] : (array) $value),
            default  => $value,
        };
    }

    /** @template T of object */
    private function getAttribute(ReflectionProperty $prop, string $attributeClass): ?object
    {
        $attrs = $prop->getAttributes($attributeClass, ReflectionAttribute::IS_INSTANCEOF);
        return $attrs ? $attrs[0]->newInstance() : null;
    }
}

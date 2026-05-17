<?php

declare(strict_types=1);

namespace Survos\ImportBundle\Service;

/**
 * Resolves the import/mapping DTO class for a given dataset key.
 *
 * Resolution order:
 *   1. Explicit mapping  (dataset → FQCN, registered via config or DI)
 *   2. Convention        (namespace root + classified provider/code + \Obj)
 *
 * Convention examples with root 'App\Dto':
 *   mus/aust  → App\Dto\Aust\Obj
 *   mus/victoria → App\Dto\Victoria\Obj
 *
 * Convention examples with root 'App\Dto\Source':
 *   mus/aust  → App\Dto\Source\Mus\Aust\Obj
 *   dc        → App\Dto\Source\Dc\DcObj
 *
 * Each app configures its own namespace root(s) via survos_import.dto_namespace_roots.
 */
final class DtoClassResolver
{
    /**
     * @param array<string, class-string> $explicit        dataset → FQCN
     * @param list<string>                $namespaceRoots  e.g. ['App\Dto', 'App\Dto\Source']
     */
    public function __construct(
        private readonly array $explicit = [],
        private readonly array $namespaceRoots = [],
    ) {}

    /** @return class-string|null */
    public function resolve(string $dataset): ?string
    {
        if (isset($this->explicit[$dataset])) {
            return $this->explicit[$dataset];
        }

        foreach ($this->candidates($dataset) as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function candidates(string $dataset): array
    {
        [$provider, $code] = str_contains($dataset, '/')
            ? explode('/', $dataset, 2)
            : [$dataset, null];

        $p = $this->classify($provider);
        $c = $code ? $this->classify($code) : null;

        $candidates = [];
        foreach ($this->namespaceRoots as $root) {
            if ($c) {
                $candidates[] = $root . '\\' . $c . '\\Obj';           // App\Dto\Aust\Obj
                $candidates[] = $root . '\\' . $p . '\\' . $c . '\\Obj'; // App\Dto\Mus\Aust\Obj
            }
            $candidates[] = $root . '\\' . $p . '\\' . $p . 'Obj';     // App\Dto\Dc\DcObj
            $candidates[] = $root . '\\' . $p . 'Obj';                  // App\Dto\DcObj
        }

        return $candidates;
    }

    private function classify(string $value): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $value) ?: [];
        return implode('', array_map(
            static fn(string $p): string => ucfirst(strtolower($p)),
            array_filter($parts)
        ));
    }
}

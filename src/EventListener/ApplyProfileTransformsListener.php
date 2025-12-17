<?php
declare(strict_types=1);

namespace Survos\ImportBundle\EventListener;

use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class ApplyProfileTransformsListener
{
    /** @var array<string, array<string, array{delimiter:string,trim:bool,minParts:int}>> profilePath => rules */
    private array $cache = [];

    #[AsEventListener(event: ImportConvertRowEvent::class)]
    public function onRow(ImportConvertRowEvent $event): void
    {
        $profilePath = $event->applyProfilePath;

        if ($profilePath === null || $profilePath === '') {
            return;
        }
        if (!is_file($profilePath)) {
            return;
        }

        $rules = $this->loadSplitRules($profilePath);
        if ($rules === []) {
            return;
        }

        $row = $event->row;
        if (!is_array($row)) {
            return;
        }

        foreach ($rules as $field => $rule) {
            if (!array_key_exists($field, $row)) {
                continue;
            }

            $v = $row[$field];

            // already normalized
            if (is_array($v) || $v === null) {
                continue;
            }
            if (!is_scalar($v)) {
                continue;
            }

            $s = (string) $v;
            if ($s === '' || !str_contains($s, $rule['delimiter'])) {
                continue;
            }

            $parts = explode($rule['delimiter'], $s);
            if ($rule['trim']) {
                $parts = array_map('trim', $parts);
            }
            $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

            if (count($parts) >= $rule['minParts']) {
                $row[$field] = $parts;
            }
        }

        $event->row = $row;
    }

    /**
     * @return array<string, array{delimiter:string,trim:bool,minParts:int}>
     */
    private function loadSplitRules(string $profilePath): array
    {
        if (isset($this->cache[$profilePath])) {
            return $this->cache[$profilePath];
        }

        $json = json_decode((string) file_get_contents($profilePath), true);
        if (!is_array($json)) {
            return $this->cache[$profilePath] = [];
        }

        $rules = [];
        $split = $json['transforms']['split'] ?? [];
        if (!is_array($split)) {
            return $this->cache[$profilePath] = [];
        }

        foreach ($split as $r) {
            if (!is_array($r)) {
                continue;
            }
            $field = $r['field'] ?? null;
            $delim = $r['delimiter'] ?? null;
            if (!is_string($field) || $field === '' || !is_string($delim) || $delim === '') {
                continue;
            }

            $rules[$field] = [
                'delimiter' => $delim,
                'trim'      => (bool) ($r['trim'] ?? true),
                'minParts'  => (int) ($r['minParts'] ?? 2),
            ];
        }

        return $this->cache[$profilePath] = $rules;
    }
}

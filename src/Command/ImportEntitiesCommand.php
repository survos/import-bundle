<?php

declare(strict_types=1);

namespace Survos\ImportBundle\Command;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\MappingException;
use League\Csv\Reader as CsvReader;
use JsonMachine\Items;
use Survos\CoreBundle\Service\EntityClassResolver;
// is this best?
use Survos\JsonlBundle\IO\JsonlReader as SurvosJsonlReader;
use Survos\ImportBundle\Service\LooseObjectMapper;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

#[AsCommand('import:entities', 'Import records from a CSV/TSV/JSON/JSONL into a Doctrine entity')]
final class ImportEntitiesCommand
{
    public function __construct(
        private LooseObjectMapper $mapper,
        private string $dataDir, // injected from $config
        private readonly EntityClassResolver $resolver,
        private ?EntityManagerInterface $em = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Entity class or short name (e.g. "Wam")')]
        ?string $entityClass = null,
        #[Argument('Path to CSV/TSV/JSON/JSONL file')]
        ?string $file = null,
        #[Option(description: 'Primary key field (defaults to entity identifier or heuristics)')]
        ?string $pk = null,
        #[Option(description: 'Limit number of records to import')]
        ?int $limit = null,
        #[Option(description: 'Flush/clear batch size')]
        int $batch = 500,
        #[Option(description: 'Purge table before import')]
        bool $reset = false,
        #[Option(description: 'Verbose per-batch progress')]
        bool $progress = true,
        #[Option(description: 'no id, use auto-increment', name: 'auto')]
        bool $idIsLineNumber = false,
    ): int {
        if (!$this->em) {
            $io->error("composer req doctrine/orm");
            return Command::FAILURE;
        }


        if (!$entityClass) {
            $entityClass = $io->askQuestion(new ChoiceQuestion("Entity class?", $this->getAllEntityClasses()));
        }
        if (!class_exists($entityClass)) {
            $entityClass = 'App\\Entity\\' . $entityClass;
        }

        $entityClass = $this->resolver->resolve($entityClass);
        if (!class_exists($entityClass)) {
            $io->error("Entity class not found: $entityClass");
            return Command::FAILURE;
        }

        if (!$file) {
            $files = glob($this->dataDir . '/*');
            $file = $io->askQuestion(new ChoiceQuestion("What file would you like to import", $files));
        }
        if (!file_exists($file)) {
            $io->error("File not found: $file");
            return Command::FAILURE;
        }

        // Resolve PK field: prefer Doctrine metadata, else user-provided, else heuristic
        if (!$idIsLineNumber) {
            $pkField = $pk ?? $this->resolvePrimaryKey($entityClass);
            if (!$pkField) {
                $io->warning('No obvious primary key found; proceeding without upsert (always insert).');
                return Command::FAILURE;
            } else {
                $io->writeln("Using primary key: <info>$pkField</info>");
            }
        }

        if ($reset) {
            $deleted = $this->em->createQueryBuilder()
                ->delete($entityClass, 'e')->getQuery()->execute();
            $io->writeln("Deleted $deleted existing rows.");
        }

        $i = 0;
        foreach ($this->iterateFile($file) as $idx => $row) {
            // coerce each value smartly
            foreach ($row as $k => $v) {
                $row[$k] = $this->coerceValue($k, $v);
            }

            if ($idIsLineNumber) {
                $pkValue = $idx;
            } elseif ($pkField) {
                if ($rowKey = $this->mapper->resolveRowKey($row, $pkField)) {

                    $pkValue = $row[$pkField] ?? null;

                    $rp = new \ReflectionProperty($entityClass, $pkField);
                    $type = $rp->getType();
                    if ($type instanceof \ReflectionNamedType && $type->getName() === 'string') {
                        $pkValue = $pkValue !== null ? (string) $pkValue : null;
                    }

// then assign to the entity

//                    dump($rowKey);
                    if (empty($pkValue)) {
                        $io->warning("Skipping row $rowKey, no primary key found.");
//                        dump($row);
                        continue;
                    }
                } else {
                    dd(array_keys($row), $pkField, $entityClass);
                }
                assert($pkField, "No pk field found.");
                assert($pkValue, "No pk value found for " . $pkField);
            }

//            if ($pkValue) {
            if (!$entity = $this->em->getRepository($entityClass)->find($pkValue)) {
                $entity = new $entityClass();
                if (!$idIsLineNumber) {
                    try {
                        $entity->$pkField = $pkValue;
                    } catch (\Exception $e) {
                        $io->error($e->getMessage());
                        dd($pkField, $pkValue);

                    }
                }
                $this->em->persist($entity);
            }
//            }
//            dd($entity, $pkValue, $pkField);
//
//            if (!$entity) {
//                $entity = new $entityClass();
//                // if we have PK, set it directly (leave mapper to handle the rest)
//                if ($pkField) {
//                    if ($rowKey = $this->mapper->resolveRowKey($row, $pkField)) {
//                            // direct set for public properties, but need to match type.
//                        try {
//                            $entity->$pkField = $row[$rowKey];
//                        } catch (\Throwable) {
//                            // ignore; LooseObjectMapper will handle during map()
//                        }
//                    }
//                }
//                if (!$this->propertyAccessor->getValue($entity, $pkField)) {
//                    continue;
//                }
//                // if there's no PK, use $idx
//                if (!$entity->$pkField) {
//                    dump($row);
//                    continue;
//                }
//                $this->em->persist($entity);
//            }

            // Map remaining data into entity; ignore PK
            $ignore = $pkField??false ? [$pkField] : [];
            $this->mapper->mapInto($row, $entity, ignored: $ignore);

            $i++;
            if ($batch > 0 && ($i % $batch) === 0) {
                if ($progress) {
                    $io->writeln("... $i");
                }
                $this->em->flush();
                $this->em->clear();
            }
            if ($limit && $i >= $limit) {
                break;
            }
        }

        $this->em->flush();
        $io->success(sprintf("%d now in $entityClass", $this->em->getRepository($entityClass)->count()));
        return Command::SUCCESS;
    }

    private function guessPkField(string $entityClass): ?string
    {
        /** @var ClassMetadata $m */
        $m = $this->em->getClassMetadata($entityClass);
        $ids = $m->getIdentifierFieldNames();
        return $ids[0] ?? null; // handle only single-field PKs here
    }


    private function resolvePrimaryKey(string $entityClass): ?string
    {
        try {
            $meta = $this->em->getClassMetadata($entityClass);
        } catch (MappingException) {
            return null;
        }
        $ids = $meta->getIdentifier();
        if (\count($ids) === 1) {
            return $ids[0];
        }

        // heuristic if metadata has no single id yet (or not managed)
        $candidates = ['id','code','sku','ssn','uid','uuid','key'];
        foreach ($candidates as $c) {
            if ($meta->hasField($c) || $meta->hasField(strtolower($c))) {
                return $c;
            }
        }
        return null;
    }

    private function getAllEntityClasses(): array
    {
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();

        return array_map(
            fn($meta) => $meta->getName(),
            $metadata
        );
    }

    /** @return \Generator<array<string,mixed>> */
    private function iterateFile(string $path): \Generator
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // CSV/TSV (delimiter sniff, quoted fields supported)
        if (in_array($ext, ['csv','tsv','txt'], true)) {
            $sample = file_get_contents($path, false, null, 0, 8192) ?: '';
            $delimiter = str_contains($sample, "\t") ? "\t" : ',';

            $csv = CsvReader::from($path, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter($delimiter);
            $csv->setEnclosure('"');

            foreach ($csv->getRecords() as $row) {
                yield (array) $row;
            }
            return;
        }

        // JSON (stream first-level items)
        if ($ext === 'json') {
            foreach (Items::fromFile($path) as $item) {
                yield is_array($item) ? $item : (array) $item;
            }
            return;
        }

        // JSONL (prefer Survos reader; fallback to fgets)
        if (in_array($ext, ['jsonl','ndjson'], true)) {
            if (class_exists(SurvosJsonlReader::class)) {
                $reader = new SurvosJsonlReader($path);
                foreach ($reader as $row) {
                    yield (array) $row;
                }
                return;
            }
            $fh = fopen($path, 'r');
            if ($fh === false) {
                throw new \RuntimeException("Unable to open $path");
            }
            try {
                while (($line = fgets($fh)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $row = json_decode($line, true);
                    if (is_array($row)) {
                        yield $row;
                    }
                }
            } finally {
                fclose($fh);
            }
            return;
        }

        throw new \InvalidArgumentException("Unsupported file extension: .$ext (use csv/tsv/txt, json, or jsonl)");
    }

    private function coerceValue(string $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if (!is_string($value)) {
            return $value;
        }

        $v = trim($value);
        if ($v == '') {
            return null;
        }

        // plural fields with comma or pipe => array
        $looksPlural = static function (string $name): bool {
            $n = strtolower($name);
            if (\in_array($n, ['is','has','was','ids','status'], true)) {
                return false;
            }
            return str_ends_with($n, 's');
        };
        if ($looksPlural($field) && (str_contains($v, ',') || str_contains($v, '|'))) {
            $parts = preg_split('/[|,]/', $v);
            $parts = array_map(static fn(string $s) => trim($s), $parts);
            return array_values(array_filter($parts, static fn($s) => $s !== ''));
        }

        // pipe-only convenience
        if (str_contains($v, '|')) {
            $parts = array_map(static fn(string $s) => trim($s), explode('|', $v));
            return array_values(array_filter($parts, static fn($s) => $s !== ''));
        }

        // booleans
        $l = strtolower($v);
        if (in_array($l, ['true','false','yes','no','y','n','on','off','1','0'], true)) {
//            return in_array($l, ['true','yes','y','on','1'], true);
            return in_array($l, ['true','yes','y','on'], true);
        }

        // ISO 8601 datetime
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/', $v) === 1) {
            try {
                return new DateTimeImmutable($v);
            } catch (\Throwable) {
            }
        }

        // integers (avoid zero-padded codes unless “numeric preferred”)
        $numericPreferred = [
            'page','count','index','position','rank','duration','size',
            'budget','revenue','popularity','score','rating','price','quantity','year','votes',
        ];
        $preferNumeric = in_array(strtolower($field), $numericPreferred, true);

        if (preg_match('/^-?\d+$/', $v) === 1) {
            $hasLeadingZero = strlen($v) > 1 && $v[0] === '0';
            return ($preferNumeric || !$hasLeadingZero) ? (int)$v : $v;
        }

        // floats (incl. scientific)
        if (is_numeric($v) && preg_match('/^-?(?:\d+\.\d+|\d+\.|\.\d+|\d+)(?:[eE][+\-]?\d+)?$/', $v) === 1) {
            return (float)$v;
        }

        return $v;
    }
}

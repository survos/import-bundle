<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    'import:profile:report',
    'Pretty-print a .profile.json summary (tables + filters), so you do not have to read raw JSON.'
)]
final class ImportProfileReportCommand
{
    public function __construct(
        private readonly string $dataDir,
        private readonly ?\Survos\ImportBundle\Contract\DatasetPathsFactoryInterface $pathsFactory = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Profile JSON path (defaults to <dataDir>/<dataset>.profile.json)')]
        ?string $profile = null,
        #[Option('Dataset code to infer the profile path if not provided')]
        ?string $dataset = null,
        #[Option('Only show: split|nl|image|url|json|pk')]
        ?string $only = null,
        #[Option('Sort by: name|distinct|nulls|avgLen|maxLen')]
        string $sort = 'name',
        #[Option('Limit rows (0 = no limit)')]
        int $limit = 0,
        #[Option('Regex filter for field name (e.g. "/title|name/i")')]
        ?string $match = null,
        #[Option('Show transforms block (if present)')]
        bool $showTransforms = false,
    ): int {
        $io->title('Profile Report');

        $profilePath = $this->resolveProfilePath($profile, $dataset);

        if ($profilePath === null || !is_file($profilePath)) {
            $io->error(sprintf('Profile file not found: %s', $profilePath ?? '(null)'));
            return Command::FAILURE;
        }

        $raw = file_get_contents($profilePath);
        if ($raw === false) {
            $io->error(sprintf('Unable to read profile file: %s', $profilePath));
            return Command::FAILURE;
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $io->error('Profile JSON is invalid or not an object.');
            return Command::FAILURE;
        }

        $fields = $json['fields'] ?? [];
        if (!is_array($fields)) {
            $io->error('Profile does not contain a "fields" object.');
            return Command::FAILURE;
        }

        $io->section('Summary');
        $io->definitionList(
            ['Profile' => $profilePath],
            ['Dataset' => (string) ($json['dataset'] ?? '')],
            ['Input' => (string) ($json['input'] ?? '')],
            ['Output' => (string) ($json['output'] ?? '')],
            ['Record count' => (string) ($json['recordCount'] ?? '')],
            ['Unique fields' => implode(', ', (array) ($json['uniqueFields'] ?? []))],
            ['Tags' => implode(', ', (array) ($json['tags'] ?? []))],
        );

        if ($showTransforms) {
            $io->section('Transforms');
            $io->writeln(json_encode($json['transforms'] ?? new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $rows = [];
        foreach ($fields as $name => $s) {
            if (!is_array($s)) {
                continue;
            }

            if ($match !== null && $match !== '') {
                $ok = @preg_match($match, (string) $name);
                if ($ok !== 1) {
                    continue;
                }
            }

            $row = $this->rowForField((string) $name, $s);

            if ($only !== null && $only !== '') {
                if (!$this->passesOnlyFilter($only, $row, $s, (array) ($json['uniqueFields'] ?? []))) {
                    continue;
                }
            }

            $rows[] = $row;
        }

        $rows = $this->sortRows($rows, $sort);

        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $io->section(sprintf('Fields (%d)', count($rows)));
        $io->table(
            ['Field', 'Types', 'Hint', 'Nulls', 'Distinct', 'Len(avg/min/max)', 'Flags', 'Split'],
            array_map(static function (array $r): array {
                return [
                    $r['name'],
                    $r['types'],
                    $r['storageHint'],
                    $r['nulls'],
                    $r['distinct'],
                    $r['len'],
                    $r['flags'],
                    $r['split'],
                ];
            }, $rows)
        );

        $io->note('Tip: --only=split|nl|image|url|json and --match="/regex/" and --sort=distinct|avgLen');

        return Command::SUCCESS;
    }

    private function resolveProfilePath(?string $profile, ?string $dataset): ?string
    {
        if (is_string($profile) && $profile !== '') {
            return $profile;
        }

        if (is_string($dataset) && $dataset !== '') {
            if ($this->pathsFactory !== null) {
                $paths = $this->pathsFactory->for($dataset);
                return $paths->profileObjectPath();
            }

            $dir = rtrim($this->dataDir, '/');
            return sprintf('%s/%s.profile.json', $dir, $dataset);
        }

        return null;
    }

    private function profilePathFromJsonl(string $jsonlPath): string
    {
        $dir = dirname($jsonlPath);
        $filename = basename($jsonlPath);

        if (str_ends_with($filename, '.gz')) {
            $filename = substr($filename, 0, -3);
        }

        $filename = preg_replace('/\.(jsonl|json)$/i', '', $filename, 1);

        return sprintf('%s/%s.profile.json', $dir, $filename);
    }

    /**
     * @param array<string,mixed> $s
     * @return array{name:string,types:string,storageHint:string,nulls:string,distinct:string,len:string,flags:string,split:string,_sort:array<string,float|int|string>}
     */
    private function rowForField(string $name, array $s): array
    {
        $types = $s['types'] ?? [];
        $typesStr = is_array($types) ? implode(',', $types) : (string) $types;

        $nulls = (string) ($s['nulls'] ?? '');
        $distinct = (string) ($s['distinct'] ?? '');

        $len = $s['stringLengths'] ?? [];
        $avg = is_array($len) ? ($len['avg'] ?? null) : null;
        $min = is_array($len) ? ($len['min'] ?? null) : null;
        $max = is_array($len) ? ($len['max'] ?? null) : null;

        $lenStr = sprintf(
            '%s/%s/%s',
            $avg === null ? '' : (is_float($avg) ? sprintf('%.1f', $avg) : (string) $avg),
            $min === null ? '' : (string) $min,
            $max === null ? '' : (string) $max
        );

        $flags = [];
        foreach (['booleanLike','urlLike','jsonLike','imageLike','naturalLanguageLike'] as $k) {
            if (!empty($s[$k])) {
                $flags[] = $k;
            }
        }
        if (!empty($s['localeGuess'])) {
            $flags[] = 'locale:' . $s['localeGuess'];
        }

        $split = '';
        if (isset($s['splitCandidate']) && is_array($s['splitCandidate']) && !empty($s['splitCandidate']['enabled'])) {
            $split = sprintf(
                '%s (r=%s c=%s)',
                (string) ($s['splitCandidate']['delimiter'] ?? ''),
                (string) ($s['splitCandidate']['ratio'] ?? ''),
                (string) ($s['splitCandidate']['confidence'] ?? '')
            );
        }

        $storageHint = (string) ($s['storageHint'] ?? '');

        return [
            'name' => $name,
            'types' => $typesStr,
            'storageHint' => $storageHint,
            'nulls' => $nulls,
            'distinct' => $distinct,
            'len' => $lenStr,
            'flags' => implode(' ', $flags),
            'split' => $split,

            // sort keys
            '_sort' => [
                'name' => $name,
                'distinct' => (int) ($s['distinct'] ?? 0),
                'nulls' => (int) ($s['nulls'] ?? 0),
                'avgLen' => is_numeric($avg) ? (float) $avg : -1.0,
                'maxLen' => is_numeric($max) ? (int) $max : -1,
            ],
        ];
    }

    /**
     * @param array<int,array{_sort:array<string,float|int|string>}> $rows
     * @return array<int,array{_sort:array<string,float|int|string>}>
     */
    private function sortRows(array $rows, string $sort): array
    {
        $key = match ($sort) {
            'distinct' => 'distinct',
            'nulls' => 'nulls',
            'avgLen' => 'avgLen',
            'maxLen' => 'maxLen',
            default => 'name',
        };

        usort($rows, static function (array $a, array $b) use ($key): int {
            $av = $a['_sort'][$key] ?? null;
            $bv = $b['_sort'][$key] ?? null;

            // Desc for numeric sorts
            if ($key !== 'name') {
                return ($bv <=> $av);
            }

            return (string) $av <=> (string) $bv;
        });

        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $stats
     * @param string[] $uniqueFields
     */
    private function passesOnlyFilter(string $only, array $row, array $stats, array $uniqueFields): bool
    {
        $only = strtolower(trim($only));

        return match ($only) {
            'split' => isset($stats['splitCandidate']) && is_array($stats['splitCandidate']) && !empty($stats['splitCandidate']['enabled']),
            'nl'    => !empty($stats['naturalLanguageLike']),
            'image' => !empty($stats['imageLike']),
            'url'   => !empty($stats['urlLike']),
            'json'  => !empty($stats['jsonLike']),
            'pk'    => in_array($row['name'], $uniqueFields, true),
            default => true,
        };
    }
}

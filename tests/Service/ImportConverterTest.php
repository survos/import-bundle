<?php

declare(strict_types=1);

namespace Survos\ImportBundle\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Survos\ImportBundle\Event\ImportConvertStartedEvent;
use Survos\ImportBundle\Service\CsvProfileExporter;
use Survos\ImportBundle\Service\ImportConverter;
use Survos\ImportBundle\Service\Provider\CsvRowProvider;
use Survos\ImportBundle\Service\Provider\JsonlRowProvider;
use Survos\ImportBundle\Service\Provider\RowProviderRegistry;
use Survos\ImportBundle\Service\RowNormalizer;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\Sqlite\SqlProfiler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ImportConverter::class)]
final class ImportConverterTest extends TestCase
{
    private string $dir;
    private EventDispatcher $dispatcher;
    private ImportConverter $converter;
    private SymfonyStyle $io;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir().'/import-convert-'.\bin2hex(\random_bytes(8));
        (new Filesystem())->mkdir($this->dir);
        $this->dispatcher = new EventDispatcher();
        $this->converter = new ImportConverter(
            new RowProviderRegistry([new CsvRowProvider(), new JsonlRowProvider()]),
            new RowNormalizer(),
            new CsvProfileExporter(),
            $this->dispatcher,
            new SqlProfiler(),
        );
        $this->io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    #[Test]
    public function csvCommandConvertsWithoutDatasetServicesOrInferredContext(): void
    {
        $dir = $this->dir.'/work/provider/collection/05_raw';
        (new Filesystem())->mkdir($dir);
        $input = $dir.'/items.csv';
        \file_put_contents($input, "ID,Title\n1,First\n2,Second\n");
        $contexts = [];
        $this->dispatcher->addListener(ImportConvertRowEvent::class, static function (ImportConvertRowEvent $event) use (&$contexts): void {
            $contexts[] = $event->dataset;
        });
        $this->dispatcher->addListener(ImportConvertStartedEvent::class, static function (): void {
            self::fail('A bare conversion must not start the dataset lifecycle.');
        });
        $command = new Command(code: $this->converter->convert(...));
        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, '--limit' => 1]));
        self::assertSame([['id' => 1, 'title' => 'First']], $this->rows($dir.'/items.jsonl'));
        self::assertSame([''], $contexts);
        self::assertStringContainsString('2,Second', \file_get_contents($input));
        self::assertFalse($command->getDefinition()->hasOption('provider'));
        self::assertFalse($command->getDefinition()->hasOption('all-cores'));
    }

    #[Test]
    public function explicitPathsPreserveRowAndLifecycleEvents(): void
    {
        $input = $this->dir.'/input.csv';
        $output = $this->dir.'/chosen/result.jsonl';
        \file_put_contents($input, "ID,Title\n1,First\n");
        $events = [];
        foreach ([ImportConvertStartedEvent::class, ImportConvertRowEvent::class, ImportConvertFinishedEvent::class] as $eventClass) {
            $this->dispatcher->addListener($eventClass, static function (object $event) use (&$events): void {
                $events[] = $event;
                if ($event instanceof ImportConvertRowEvent) {
                    self::assertSame('normalize', $event->stage);
                    $event->row['mapped'] = true;
                }
            });
        }
        self::assertSame(Command::SUCCESS, $this->converter->convert($this->io,
            input: $input, output: $output, dataset: 'provider/collection', core: 'obj',
        ));
        self::assertCount(3, $events);
        foreach ($events as $event) {
            self::assertSame('provider/collection', $event->dataset);
            self::assertSame($input, $event->input);
        }
        self::assertSame($output, $events[0]->jsonlPath);
        self::assertSame($this->dir.'/chosen/result.profile.json', $events[2]->profilePath);
        self::assertSame(1, $events[2]->recordCount);
        self::assertSame([['id' => 1, 'title' => 'First', 'mapped' => true]], $this->rows($output));
    }

    #[Test]
    public function datasetContextCannotSubstituteForExplicitPaths(): void
    {
        $output = $this->dir.'/result.jsonl';
        \file_put_contents($output, "keep me\n");
        self::assertSame(Command::FAILURE, $this->converter->convert($this->io,
            output: $output, dataset: 'provider/collection',
        ));
        self::assertSame("keep me\n", \file_get_contents($output));
        self::assertSame(Command::FAILURE, $this->converter->convert($this->io,
            input: $output, dataset: 'provider/collection',
        ));
        self::assertSame("keep me\n", \file_get_contents($output));
    }

    #[Test]
    public function enrichReadsExplicitClaimsAndWritesCompressedOutput(): void
    {
        $input = $this->dir.'/normalized.jsonl';
        $output = $this->dir.'/assembled.jsonl.gz';
        $claims = $this->dir.'/claims.jsonl';
        \file_put_contents($input, \json_encode([ItemField::ID => 'one', ItemField::TITLE => 'Original'])."\n");
        \file_put_contents($claims, \json_encode(['subjectId' => 'one', 'predicate' => 'customClaim', 'value' => 'enriched'])."\n");
        self::assertSame(Command::SUCCESS, $this->converter->convert($this->io,
            input: $input, output: $output, dataset: 'p/c', stage: 'enrich', claimsFile: $claims,
        ));
        self::assertSame([[ItemField::ID => 'one', ItemField::TITLE => 'Original', 'customClaim' => 'enriched']], $this->rows($output));
    }

    #[Test]
    public function explicitOutputCannotOverwriteTheInput(): void
    {
        $input = $this->dir.'/input.jsonl.gz';
        $data = 'compressed source must survive';
        \file_put_contents($input, $data);
        self::assertSame(Command::FAILURE, $this->converter->convert($this->io,
            input: $input, output: $input, dataset: 'p/c',
        ));
        self::assertSame($data, \file_get_contents($input));
    }

    #[Test]
    public function profileOnlyKeepsInputAndCreatesSqlSidecar(): void
    {
        $input = $this->dir.'/input.jsonl';
        $data = "{\"id\":1,\"title\":\"First\"}\n";
        \file_put_contents($input, $data);
        self::assertSame(Command::SUCCESS, $this->converter->convert($this->io,
            input: $input, profileOnly: true, profile: true,
        ));
        self::assertSame($data, \file_get_contents($input));
        self::assertFileExists($input.'.db');
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $path): array
    {
        return \iterator_to_array(JsonlReader::open($path), false);
    }
}

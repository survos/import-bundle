<?php

declare(strict_types=1);

namespace Survos\ImportBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\DuckDbBundle\ConnectionFactory;
use Survos\DuckDbBundle\RecordReader;
use Survos\DuckDbBundle\RecordWriter;
use Survos\ImportBundle\Service\CsvProfileExporter;
use Survos\ImportBundle\Service\ImportConverter;
use Survos\ImportBundle\Service\Provider\CsvRowProvider;
use Survos\ImportBundle\Service\Provider\DuckDbRowProvider;
use Survos\ImportBundle\Service\Provider\JsonlRowProvider;
use Survos\ImportBundle\Service\Provider\JsonRowProvider;
use Survos\ImportBundle\Service\Provider\ProviderContext;
use Survos\ImportBundle\Service\Provider\RowProviderRegistry;
use Survos\ImportBundle\Service\RowNormalizer;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

final class DuckDbConversionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!class_exists(RecordWriter::class) || !in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Optional DuckDB bundle and native PDO driver are required.');
        }
        $this->root = sys_get_temp_dir().'/duckdb-convert-'.bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
    }

    private function converter(): ImportConverter
    {
        return new ImportConverter(new RowProviderRegistry([new DuckDbRowProvider(), new JsonlRowProvider(), new CsvRowProvider(), new JsonRowProvider()]), new RowNormalizer(), new CsvProfileExporter());
    }

    private function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }

    public function testRawJsonlExportPreservesValuesAndReadsBackThroughGenericColumnSelection(): void
    {
        $rows = [['source_id' => '001', 'empty' => '', 'bool' => 'false', 'mixed' => ['one', 1, null, []], 'shape' => ['a' => 1]], ['source_id' => '002', 'shape' => null]];
        file_put_contents($this->root.'/input.jsonl', implode("\n", array_map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR), $rows))."\n");
        $db = $this->root.'/output.duckdb';
        self::assertSame(0, $this->converter()->convert($this->io(), input: $this->root.'/input.jsonl', output: $db, stage: 'raw'));
        $provider = new DuckDbRowProvider();
        self::assertEquals($rows, iterator_to_array($provider->iterate($db, new ProviderContext(table: 'records', jsonColumn: 'body', orderBy: 'sequence')), false));
        self::assertFileDoesNotExist($db.'.wal');
        self::assertSame(0, $this->converter()->convert($this->io(), input: $db, output: $this->root.'/normalized.jsonl.gz', table: 'records', jsonColumn: 'body', orderBy: 'sequence'));
        self::assertCount(2, iterator_to_array(JsonlReader::open($this->root.'/normalized.jsonl.gz')));
    }

    public function testArbitraryTypedTableNeedsNoSnapshotMetadata(): void
    {
        $path = $this->root.'/ordinary.duckdb';
        $db = new \PDO('duckdb:'.$path);
        $db->exec("CREATE TABLE things AS SELECT 7::INTEGER AS id, ['a','b'] AS tags, {'name':'nested'} AS details");
        $db = null;
        $rows = iterator_to_array((new DuckDbRowProvider())->iterate($path, new ProviderContext(table: 'things')), false);
        self::assertSame([['id' => 7, 'tags' => ['a', 'b'], 'details' => ['name' => 'nested']]], $rows);
    }

    public function testQuotedTableIdentifiersCannotExecuteSql(): void
    {
        $path = $this->root.'/quoted.duckdb';
        $db = new \PDO('duckdb:'.$path);
        $db->exec('CREATE TABLE "odd""name" AS SELECT 1 AS id');
        $db = null;
        self::assertSame([['id' => 1]], iterator_to_array((new DuckDbRowProvider())->iterate($path, new ProviderContext(table: 'odd"name')), false));
    }

    public function testFailedWriterLeavesPreviousSnapshotIntact(): void
    {
        $path = $this->root.'/atomic.duckdb';
        $writer = new RecordWriter($path);
        $writer->write(['id' => 1]);
        $writer->finish(['input' => 'fixture']);
        unset($writer);
        $hash = hash_file('sha256', $path);
        $writer = new RecordWriter($path);
        $writer->write(['id' => 2]);
        unset($writer);
        self::assertSame($hash, hash_file('sha256', $path));
        self::assertSame([], glob($path.'.building-*'));
    }

    public function testCsvAndJsonCanWriteDuckDb(): void
    {
        file_put_contents($this->root.'/input.csv', "id,title\n1,First\n2,Second\n");
        file_put_contents($this->root.'/input.json', '[{"id":1,"title":"First"},{"id":2,"title":"Second"}]');
        foreach (['csv', 'json'] as $ext) {
            $path = $this->root.'/'.$ext.'.duckdb';
            self::assertSame(0, $this->converter()->convert($this->io(), input: $this->root.'/input.'.$ext, output: $path, stage: 'raw'));
            self::assertCount(2, iterator_to_array((new DuckDbRowProvider())->iterate($path, new ProviderContext(jsonColumn: 'body')), false));
        }
    }

    public function testMultipleTablesAndMetadataShareOneSnapshot(): void
    {
        $path = $this->root.'/multi.duckdb';
        $writer = new RecordWriter($path, table: 'objects');
        for ($i = 0; $i < 1025; ++$i) {
            $writer->write(['id' => $i], (string) $i);
        }
        $writer->selectTable('groups');
        $writer->write(['members' => [1, 2]]);
        $writer->dataset(['name' => 'Example']);
        $writer->finish(['input' => 'fixture']);
        unset($writer);
        $db = (new ConnectionFactory())->open($path);
        self::assertSame(16384, (int) $db->query('SELECT block_size FROM pragma_database_size()')->fetchColumn());
        self::assertSame(1025, (int) $db->query('SELECT count(*) FROM objects')->fetchColumn());
        self::assertSame(['id' => 1024], (new RecordReader($db, 'objects'))->get('1024'));
        self::assertSame(['objects' => 1025, 'groups' => 1], (new RecordReader($db))->metadata()['tables']);
        self::assertEquals([['members' => [1, 2]]],iterator_to_array((new RecordReader($db,'groups'))->rows(),false));
    }
}

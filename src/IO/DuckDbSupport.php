<?php

declare(strict_types=1);

namespace Survos\ImportBundle\IO;

use Survos\DuckDbBundle\RecordWriter;

final class DuckDbSupport
{
    public static function requireAvailable(): void
    {
        if (!\class_exists(RecordWriter::class)) {
            throw new \RuntimeException('DuckDB reading and writing require survos/duckdb-bundle with record snapshot support. Install/update it with: composer require survos/duckdb-bundle');
        }
        if (!\class_exists(\PDO::class) || !\in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('DuckDB reading and writing require ext-pdo_duckdb linked to DuckDB 2.0 or newer. Install/enable the native PHP PDO DuckDB extension; the DuckDB CLI alone is insufficient.');
        }
    }
}

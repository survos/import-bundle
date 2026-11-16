<?php

declare(strict_types=1);

namespace Survos\ImportBundle\Service\Provider;

use Survos\DuckDbBundle\ConnectionFactory;
use Survos\DuckDbBundle\S3Configuration;
use Survos\ImportBundle\IO\DuckDbSupport;

final readonly class DuckDbRowProvider implements RowProviderInterface
{
    public function __construct(private ?ConnectionFactory $factory = null, private ?S3Configuration $s3 = null)
    {
    }

    public function supports(string $ext): bool
    {
        return 'duckdb' === $ext;
    }

    public function iterate(string $path, ProviderContext $ctx): \Generator
    {
        DuckDbSupport::requireAvailable();
        $db = ($this->factory ?? new ConnectionFactory())->open($path, \str_starts_with($path, 's3://') ? $this->s3 : null, 'import');
        // A table identifier, never arbitrary SQL. JSON output avoids driver-specific nested values.
        $quote = static fn (string $identifier): string => '"'.\str_replace('"', '""', $identifier).'"';
        $table = $quote($ctx->table ?? 'records');
        $expression = null === $ctx->jsonColumn ? 'to_json(r)' : 'CAST(r.'.$quote($ctx->jsonColumn).' AS JSON)';
        $order = null === $ctx->orderBy ? '' : ' ORDER BY r.'.$quote($ctx->orderBy);
        $statement = $db->query('SELECT CAST('.$expression.' AS VARCHAR) FROM '.$table.' AS r'.$order);
        while (false !== $json = $statement->fetchColumn()) {
            $row = \json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($row)) {
                throw new \RuntimeException('The selected DuckDB JSON column must contain object records.');
            }
            yield $row;
        }
    }
}

<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service\Provider;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('survos.import.row_provider')]
final class JsonRowProvider implements RowProviderInterface
{
    /**
     * Files at or above this size must be streamed with halaxa/json-machine.
     * If that (optional) package is missing we refuse rather than risk a full
     * json_decode() exhausting the memory_limit.
     */
    private const STREAM_THRESHOLD_BYTES = 16 * 1024 * 1024; // 16 MB

    public function supports(string $ext): bool
    {
        return $ext === 'json';
    }

    public function iterate(string $path, ProviderContext $ctx): \Generator
    {
        // halaxa/json-machine is a soft dependency: stream with it when present.
        if (class_exists(Items::class)) {
            yield from $this->iterateStreaming($path, $ctx);
            return;
        }

        // Not installed: the in-memory path is fine for small files, but a large
        // file would balloon into a multi-GB PHP array. Refuse with a hint.
        $size = @filesize($path);
        if ($size !== false && $size >= self::STREAM_THRESHOLD_BYTES) {
            throw new \RuntimeException(sprintf(
                'JSON file "%s" is %.1f MB; decoding it in memory would likely exhaust '
                . 'the PHP memory_limit. Install the streaming reader to import large '
                . 'JSON files: composer require halaxa/json-machine',
                $path,
                $size / 1024 / 1024,
            ));
        }

        yield from $this->iterateInMemory($path, $ctx);
    }

    /**
     * Stream rows without holding the whole document in memory. The pointer
     * targets either the document root (a bare array) or a named root key,
     * e.g. {"data": [...]}.
     */
    private function iterateStreaming(string $path, ProviderContext $ctx): \Generator
    {
        $pointer = $ctx->rootKey !== null ? '/' . $ctx->rootKey : '';

        // assoc=true => rows are associative arrays, matching json_decode(..., true).
        $items = Items::fromFile($path, [
            'pointer' => $pointer,
            'decoder' => new ExtJsonDecoder(true),
        ]);

        // json-machine yields key => value. A JSON array gives sequential int
        // keys (stream row-by-row); a JSON object gives property-name keys (the
        // whole object is one row). We only learn which on the first element.
        $isList = null;
        $object = [];
        foreach ($items as $key => $value) {
            $isList ??= \is_int($key);

            if ($isList) {
                if (\is_array($value)) {
                    yield $value;
                }
                continue;
            }

            $object[$key] = $value;
        }

        if ($isList === false) {
            yield $object;
        }
    }

    /**
     * Fallback used only when json-machine is absent and the file is small.
     */
    private function iterateInMemory(string $path, ProviderContext $ctx): \Generator
    {
        $contents = \file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read JSON file "%s".', $path));
        }

        $decoded = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('JSON root must be an object or array.');
        }

        $items = $decoded;

        if ($ctx->rootKey !== null) {
            if (!\array_key_exists($ctx->rootKey, $decoded)) {
                throw new \RuntimeException(sprintf(
                    'Root key "%s" not found in JSON. Available keys: %s',
                    $ctx->rootKey,
                    implode(', ', array_keys($decoded))
                ));
            }
            $items = $decoded[$ctx->rootKey];
            if (!\is_array($items)) {
                throw new \RuntimeException(sprintf('Value at root key "%s" is not an array.', $ctx->rootKey));
            }
        }

        if (\array_is_list($items)) {
            foreach ($items as $item) {
                if (\is_array($item)) {
                    yield $item;
                }
            }
            return;
        }

        // Object => single row
        yield $items;
    }
}

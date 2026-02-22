<?php
declare(strict_types=1);

namespace Survos\ImportBundle\Service;

use DOMDocument;
use DOMXPath;
use Survos\ImportBundle\Event\ImportDirFileEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use ZipArchive;

use function count;
use function escapeshellarg;
use function explode;
use function file_get_contents;
use function fclose;
use function finfo_close;
use function finfo_file;
use function finfo_open;
use function fopen;
use function function_exists;
use function hash;
use function hash_algos;
use function hash_final;
use function hash_init;
use function hash_update_stream;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_numeric;
use function is_resource;
use function is_string;
use function json_decode;
use function microtime;
use function min;
use function shell_exec;
use function sprintf;
use function str_starts_with;
use function trim;

final class ProbeService
{
    /** @var array<string, string> */
    private array $seenChecksums = [];

    /** @var array<string, array{path: string, values: array<int, int>}> */
    private array $audioFingerprints = [];

    public function reset(): void
    {
        $this->seenChecksums = [];
        $this->audioFingerprints = [];
    }

    #[AsEventListener(event: ImportDirFileEvent::class, priority: 256)]
    public function onImportDirFile(ImportDirFileEvent $event): void
    {
        $file = $event->file;
        $start = microtime(true);

        $isReadable = $file->fileInfo->isReadable;
        $path = $file->fileInfo->pathname;
        $extension = $file->fileInfo->extension;
        $mimeType = null;

        $file->metadata['is_readable'] = $isReadable;

        if ($event->probeLevel >= 1) {
            $file->metadata['size'] = $file->fileInfo->size;
            $file->metadata['created_time'] = $file->fileInfo->createdTime;
            $file->metadata['modified_time'] = $file->fileInfo->modifiedTime;

            if ($isReadable) {
                $mimeType = $this->detectMimeType($path);
                if ($mimeType !== null) {
                    $file->metadata['mime_type'] = $mimeType;
                }
            }

            if ($isReadable && in_array('xxh3', hash_algos(), true)) {
                $checksum = $this->computeXxh3Checksum($path);
                if ($checksum !== null) {
                    $file->metadata['checksum_xxh3'] = $checksum;

                    if (isset($this->seenChecksums[$checksum])) {
                        $file->metadata['duplicate_of'] = $this->seenChecksums[$checksum];
                    } else {
                        $this->seenChecksums[$checksum] = $path;
                    }
                }
            }

            $sidecar = $this->loadSidecarData($path);
            if ($sidecar !== null) {
                $file->metadata['sidecar'] = $sidecar;
            }
        }

        if ($event->probeLevel >= 2 && $isReadable) {
            $file->metadata = [...$file->metadata, ...$this->probeDeep($path, $extension !== '' ? $extension : null, $mimeType)];
        }

        if (
            $event->probeLevel >= 3
            && $isReadable
            && $this->shouldProbeMedia($extension !== '' ? $extension : null, $mimeType)
            && $file->fileInfo->size !== null
        ) {
            $fingerprint = $this->probeAudioFingerprint($path);
            if ($fingerprint !== null) {
                $file->metadata = [...$file->metadata, ...$fingerprint['metadata']];

                if (isset($this->audioFingerprints[$fingerprint['hash']])) {
                    $file->metadata['audio_duplicate_of'] = $this->audioFingerprints[$fingerprint['hash']]['path'];
                } else {
                    foreach ($this->audioFingerprints as $existing) {
                        $similarity = $this->compareFingerprintSimilarity($fingerprint['values'], $existing['values']);
                        if ($similarity >= $event->audioSimilarity) {
                            $file->metadata['audio_similar_to'] = $existing['path'];
                            $file->metadata['audio_similarity'] = $similarity;
                            break;
                        }
                    }

                    $this->audioFingerprints[$fingerprint['hash']] = [
                        'path' => $path,
                        'values' => $fingerprint['values'],
                    ];
                }
            }
        }

        $file->metadata['probe_duration_ms'] = (int) ((microtime(true) - $start) * 1000);
    }

    private function loadSidecarData(string $path): ?array
    {
        $sidecarPath = $path . '.sidecar.json';
        if (!is_file($sidecarPath)) {
            return null;
        }

        $json = file_get_contents($sidecarPath);
        if (!is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function probeDeep(string $filepath, ?string $extension, ?string $mimeType): array
    {
        $metadata = [];

        if ($this->shouldProbeMedia($extension, $mimeType)) {
            $duration = $this->probeFfprobeDuration($filepath);
            if ($duration !== null) {
                $metadata['media_duration'] = $duration;
            }
        }

        if ($extension !== null && in_array($extension, ['docx'], true)) {
            $metadata = [...$metadata, ...$this->probeDocxMetadata($filepath)];
        }

        return $metadata;
    }

    private function shouldProbeMedia(?string $extension, ?string $mimeType): bool
    {
        if ($mimeType !== null && (str_starts_with($mimeType, 'audio/') || str_starts_with($mimeType, 'video/'))) {
            return true;
        }

        if ($extension === null) {
            return false;
        }

        return in_array($extension, ['mp3', 'wav', 'aac', 'flac', 'mp4', 'm4a', 'mov', 'mkv', 'webm'], true);
    }

    private function probeFfprobeDuration(string $filepath): ?float
    {
        $which = shell_exec('command -v ffprobe 2>/dev/null');
        if (!is_string($which) || trim($which) === '') {
            return null;
        }

        $command = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellarg($filepath)
        );
        $output = shell_exec($command);
        if (!is_string($output)) {
            return null;
        }

        $output = trim($output);
        if ($output === '' || !is_numeric($output)) {
            return null;
        }

        return (float) $output;
    }

    /**
     * @return array<string, string>
     */
    private function probeDocxMetadata(string $filepath): array
    {
        $metadata = [];
        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            return $metadata;
        }

        $coreXml = $zip->getFromName('docProps/core.xml');
        $zip->close();

        if ($coreXml === false) {
            return $metadata;
        }

        $doc = new DOMDocument();
        if (!$doc->loadXML($coreXml)) {
            return $metadata;
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $xpath->registerNamespace('dcterms', 'http://purl.org/dc/terms/');
        $xpath->registerNamespace('cp', 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties');

        $author = trim($xpath->evaluate('string(//dc:creator)'));
        if ($author !== '') {
            $metadata['docx_author'] = $author;
        }

        $title = trim($xpath->evaluate('string(//dc:title)'));
        if ($title !== '') {
            $metadata['docx_title'] = $title;
        }

        $subject = trim($xpath->evaluate('string(//dc:subject)'));
        if ($subject !== '') {
            $metadata['docx_subject'] = $subject;
        }

        $keywords = trim($xpath->evaluate('string(//cp:keywords)'));
        if ($keywords !== '') {
            $metadata['docx_keywords'] = $keywords;
        }

        $created = trim($xpath->evaluate('string(//dcterms:created)'));
        if ($created !== '') {
            $metadata['docx_created_time'] = $created;
        }

        $modified = trim($xpath->evaluate('string(//dcterms:modified)'));
        if ($modified !== '') {
            $metadata['docx_modified_time'] = $modified;
        }

        return $metadata;
    }

    private function detectMimeType(string $filepath): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mimeType = finfo_file($finfo, $filepath);
        finfo_close($finfo);

        if (!is_string($mimeType) || trim($mimeType) === '') {
            return null;
        }

        return $mimeType;
    }

    private function computeXxh3Checksum(string $filepath): ?string
    {
        $hash = hash_init('xxh3');
        $fh = fopen($filepath, 'rb');
        if (!is_resource($fh)) {
            return null;
        }

        $updated = hash_update_stream($hash, $fh);
        fclose($fh);

        if ($updated === false) {
            return null;
        }

        return hash_final($hash);
    }

    /**
     * @return array{metadata: array<string, mixed>, hash: string, values: array<int, int>}|null
     */
    private function probeAudioFingerprint(string $filepath): ?array
    {
        $which = shell_exec('command -v fpcalc 2>/dev/null');
        if (!is_string($which) || trim($which) === '') {
            return null;
        }

        $command = sprintf('fpcalc -json %s 2>/dev/null', escapeshellarg($filepath));
        $output = shell_exec($command);

        if (!is_string($output) || trim($output) === '') {
            return null;
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded) || !isset($decoded['fingerprint']) || !is_array($decoded['fingerprint'])) {
            return null;
        }

        $values = [];
        foreach ($decoded['fingerprint'] as $value) {
            if (!is_numeric($value)) {
                return null;
            }

            $values[] = (int) $value;
        }

        if ($values === []) {
            return null;
        }

        $fingerprintHash = hash('sha1', implode(',', $values));
        $metadata = [
            'audio_fingerprint' => $values,
            'audio_fingerprint_hash' => $fingerprintHash,
        ];

        if (isset($decoded['duration']) && is_numeric($decoded['duration'])) {
            $metadata['audio_fingerprint_duration'] = (float) $decoded['duration'];
        }

        return [
            'metadata' => $metadata,
            'hash' => $fingerprintHash,
            'values' => $values,
        ];
    }

    /**
     * @param array<int, int> $a
     * @param array<int, int> $b
     */
    private function compareFingerprintSimilarity(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $matches = 0;
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] === $b[$i]) {
                $matches++;
            }
        }

        return $matches / $len;
    }
}

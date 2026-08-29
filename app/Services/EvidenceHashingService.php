<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessEvidenceChunkThreatJob;
use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class EvidenceHashingService
{
    /**
     * The genesis hash used to seed the very first link in the chain.
     */
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly EvidenceStorageService $storage)
    {
    }

    /**
     * Stream a raw chunk to durable storage while building the tamper-evident hash chain.
     *
     * The incoming stream is written to a temporary file in fixed-size buffers so the
     * full payload is never held in memory, keeping ingest safe from memory exhaustion.
     *
     * @param  resource  $stream  The raw input stream (e.g. php://input).
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy_meters?: float|null, captured_at?: string|null, extension?: string|null}  $metadata
     */
    public function processChunk(EvidenceSession $session, $stream, array $metadata = []): EvidenceChunk
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('A valid readable stream is required to process a chunk.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'evidence_chunk_');

        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to allocate a temporary file for chunk ingest.');
        }

        try {
            [$chunkHash, $byteSize] = $this->streamToTemporaryFile($stream, $temporaryPath);

            $chunk = DB::transaction(function () use ($session, $temporaryPath, $chunkHash, $byteSize, $metadata): EvidenceChunk {
                $session->refresh();

                $previousChunk = EvidenceChunk::query()
                    ->where('session_id', $session->id)
                    ->orderByDesc('sequence_number')
                    ->lockForUpdate()
                    ->first();

                $previousCumulative = $previousChunk?->cumulative_hash
                    ?? $session->chain_hash
                    ?? self::GENESIS_HASH;

                $sequenceNumber = ($previousChunk?->sequence_number ?? 0) + 1;

                $cumulativeHash = hash('sha256', $previousCumulative.$chunkHash);

                $extension = $this->sanitizeExtension((string) ($metadata['extension'] ?? 'bin'));
                $storagePath = sprintf('evidence/%s/chunks/%010d.%s', $session->id, $sequenceNumber, $extension);

                if ($this->storage->putFromPath($storagePath, $temporaryPath) !== true) {
                    throw new RuntimeException('Failed to persist evidence chunk to durable storage.');
                }

                $attributes = [
                    'session_id' => $session->id,
                    'sequence_number' => $sequenceNumber,
                    'storage_path' => $storagePath,
                    'byte_size' => $byteSize,
                    'chunk_hash' => $chunkHash,
                    'cumulative_hash' => $cumulativeHash,
                    'latitude' => $metadata['latitude'] ?? null,
                    'longitude' => $metadata['longitude'] ?? null,
                    'accuracy_meters' => $metadata['accuracy_meters'] ?? null,
                    'captured_at' => $metadata['captured_at'] ?? now(),
                ];

                if (Schema::hasColumn('evidence_chunks', 'mime_type')) {
                    $attributes['mime_type'] = EvidenceChunk::mimeFromExtension($extension);
                }

                $chunk = EvidenceChunk::create($attributes);

                $session->forceFill(['chain_hash' => $cumulativeHash])->save();

                return $chunk;
            });

            // Only trigger AI threat analysis once the ledger entry is durably
            // committed, so the queue never processes an uncommitted chunk.
            try {
                ProcessEvidenceChunkThreatJob::dispatch($chunk);
            } catch (\Throwable $exception) {
                Log::warning('Threat analysis dispatch failed after chunk ingest.', [
                    'session_id' => $session->id,
                    'chunk_id' => $chunk->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            return $chunk;
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * Copy the stream to disk in buffers, computing the SHA-256 digest and byte count on the fly.
     *
     * @param  resource  $stream
     * @return array{0: string, 1: int}  Tuple of [sha256 hex digest, total bytes].
     */
    private function streamToTemporaryFile($stream, string $temporaryPath): array
    {
        $target = fopen($temporaryPath, 'wb');

        if ($target === false) {
            throw new RuntimeException('Unable to open temporary file for writing.');
        }

        $hashContext = hash_init('sha256');
        $byteSize = 0;

        try {
            while (! feof($stream)) {
                $buffer = fread($stream, 8192);

                if ($buffer === false) {
                    throw new RuntimeException('Failed while reading from the input stream.');
                }

                if ($buffer === '') {
                    continue;
                }

                hash_update($hashContext, $buffer);
                $byteSize += strlen($buffer);

                if (fwrite($target, $buffer) === false) {
                    throw new RuntimeException('Failed while buffering the chunk to temporary storage.');
                }
            }
        } finally {
            fclose($target);
        }

        return [hash_final($hashContext), $byteSize];
    }

    private function sanitizeExtension(string $extension): string
    {
        $clean = strtolower(preg_replace('/[^a-z0-9]/', '', $extension) ?? '');

        if ($clean === '' || strlen($clean) > 8) {
            return 'bin';
        }

        return $clean;
    }
}

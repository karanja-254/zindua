<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessEvidenceChunkThreatJob;
use App\Models\AuditLog;
use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use App\Services\EvidenceHashingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class EvidenceChunkController extends Controller
{
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly EvidenceHashingService $hashingService)
    {
    }

    /**
     * Ingest a raw evidence chunk by streaming php://input straight to the hashing service.
     *
     * The request body is never buffered into memory or bound to a form field, which keeps
     * arbitrarily large captures from exhausting server memory during an emergency upload.
     */
    public function uploadChunk(Request $request, string $sessionId): JsonResponse
    {
        $session = EvidenceSession::findOrFail($sessionId);

        if ($session->status !== 'active') {
            return response()->json([
                'error' => 'WORM Policy: Chunks may only be appended to an active session.',
            ], Response::HTTP_FORBIDDEN);
        }

        $stream = fopen('php://input', 'rb');

        if ($stream === false) {
            return response()->json([
                'error' => 'Unable to read the incoming evidence stream.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $chunk = $this->hashingService->processChunk($session, $stream, [
                'latitude' => $this->nullableFloat($request->header('X-Geo-Lat')),
                'longitude' => $this->nullableFloat($request->header('X-Geo-Lng')),
                'accuracy_meters' => $this->nullableFloat($request->header('X-Geo-Accuracy')),
                'captured_at' => $request->header('X-Captured-At'),
                'extension' => $this->extensionFromRequest($request),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        AuditLog::create([
            'session_id' => $session->id,
            'actor_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'action' => 'chunk.ingested',
        ]);

        return response()->json([
            'session_id' => $session->id,
            'sequence_number' => $chunk->sequence_number,
            'chunk_hash' => $chunk->chunk_hash,
            'cumulative_hash' => $chunk->cumulative_hash,
            'byte_size' => $chunk->byte_size,
            'storage_path' => $chunk->storage_path,
            ...$this->chunkMediaFields($chunk, $session),
        ], Response::HTTP_CREATED);
    }

    /**
     * Accept a standalone image, audio, video, or document file into the hash chain.
     */
    public function uploadDirectFile(Request $request, string $sessionId): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200'],
        ]);

        $uploaded = $request->file('file');

        if ($uploaded === null) {
            return response()->json([
                'error' => 'No evidence file was provided.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $realPath = $uploaded->getRealPath();

        if ($realPath === false || $realPath === '' || ! is_file($realPath)) {
            return response()->json([
                'error' => 'Unable to read the uploaded evidence file.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $chunkHash = hash_file('sha256', $realPath);

        if ($chunkHash === false) {
            return response()->json([
                'error' => 'Unable to hash the uploaded evidence file.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $session = $this->resolveUploadSession($request, $sessionId);

            if ($session->status !== 'active') {
                return response()->json([
                    'error' => 'WORM Policy: Chunks may only be appended to an active session.',
                ], Response::HTTP_FORBIDDEN);
            }

            $extension = strtolower((string) ($uploaded->getClientOriginalExtension() ?: $uploaded->extension() ?: 'bin'));
            $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
            $storagePath = sprintf('evidence/%s/%s.%s', $session->id, (string) Str::uuid(), $extension);
            $disk = Storage::disk((string) config('filesystems.evidence_disk', 'r2'));

            $contents = file_get_contents($realPath);

            if ($contents === false) {
                return response()->json([
                    'error' => 'Unable to read the uploaded evidence file.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $stored = $disk->put($storagePath, $contents);

            if ($stored === false) {
                return response()->json([
                    'error' => 'Failed to persist evidence file to durable storage.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $chunk = DB::transaction(function () use ($session, $storagePath, $chunkHash, $uploaded): EvidenceChunk {
                $session->refresh();

                $previous = EvidenceChunk::query()
                    ->where('session_id', $session->id)
                    ->orderByDesc('sequence_number')
                    ->lockForUpdate()
                    ->first();

                $previousCumulative = $previous?->cumulative_hash
                    ?? $session->chain_hash
                    ?? self::GENESIS_HASH;

                $sequenceNumber = ($previous?->sequence_number ?? 0) + 1;
                $cumulativeHash = hash('sha256', $previousCumulative.$chunkHash);

                $created = EvidenceChunk::create([
                    'session_id' => $session->id,
                    'sequence_number' => $sequenceNumber,
                    'storage_path' => $storagePath,
                    'byte_size' => (int) $uploaded->getSize(),
                    'chunk_hash' => $chunkHash,
                    'cumulative_hash' => $cumulativeHash,
                    'captured_at' => now(),
                ]);

                $session->forceFill(['chain_hash' => $cumulativeHash])->save();

                return $created;
            });
        } catch (\Throwable $exception) {
            Log::error('Direct evidence file upload failed.', [
                'session_id' => $sessionId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'error' => 'Evidence file could not be stored.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        AuditLog::create([
            'session_id' => $session->id,
            'actor_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'action' => 'chunk.file_uploaded',
        ]);

        try {
            ProcessEvidenceChunkThreatJob::dispatch($chunk);
        } catch (\Throwable $exception) {
            Log::warning('Threat analysis dispatch failed after file upload.', [
                'session_id' => $session->id,
                'chunk_id' => $chunk->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'session_id' => $session->id,
            'chunk_id' => $chunk->id,
            ...$this->chunkMediaFields($chunk, $session),
        ], Response::HTTP_OK);
    }

    /**
     * @return array{media_url: string|null, mime_type: string, file_type: string, signed_url: string|null}
     */
    private function chunkMediaFields(EvidenceChunk $chunk, EvidenceSession $session): array
    {
        $disk = Storage::disk((string) config('filesystems.evidence_disk', 'r2'));
        $signedUrl = null;

        try {
            $signedUrl = $disk->temporaryUrl($chunk->storage_path, now()->addMinutes(5));
        } catch (\Throwable) {
            $signedUrl = null;
        }

        $proxyUrl = url('/api/v1/evidence/'.$session->id.'/chunks/'.$chunk->sequence_number.'/media');

        return [
            'signed_url' => $signedUrl,
            'media_url' => $signedUrl ?? $proxyUrl,
            'mime_type' => $chunk->mimeType(),
            'file_type' => $chunk->fileType(),
        ];
    }

    private function resolveUploadSession(Request $request, string $sessionId): EvidenceSession
    {
        if ($sessionId === '' || strtolower($sessionId) === 'new') {
            return EvidenceSession::create([
                'user_id' => $request->user()?->id,
                'status' => 'active',
                'risk_level' => 'unassessed',
                'started_at' => now(),
            ]);
        }

        return EvidenceSession::findOrFail($sessionId);
    }

    /**
     * Normalize an optional numeric header into a float or null.
     */
    private function nullableFloat(?string $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function extensionFromRequest(Request $request): string
    {
        $header = strtolower((string) $request->header('X-Chunk-Ext', ''));

        if ($header !== '' && preg_match('/^[a-z0-9]{1,8}$/', $header) === 1) {
            return $header;
        }

        $mime = strtolower((string) $request->header('Content-Type', ''));

        return match (true) {
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'mp4') => 'mp4',
            str_contains($mime, 'quicktime') => 'mov',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'wav') => 'wav',
            str_contains($mime, 'mpeg') || str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'aac') || str_contains($mime, 'm4a') => 'm4a',
            str_contains($mime, 'jpeg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'pdf') => 'pdf',
            default => 'bin',
        };
    }
}

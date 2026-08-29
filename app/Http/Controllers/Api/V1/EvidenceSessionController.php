<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\BroadcastTelegramAlertJob;
use App\Models\AuditLog;
use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use App\Services\EvidenceMediaService;
use App\Services\ForensicReportService;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class EvidenceSessionController extends Controller
{
    /**
     * Return paginated evidence sessions with chunk counts, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = EvidenceSession::query()
            ->withCount('chunks')
            ->orderByDesc('created_at')
            ->paginate(15);

        $sessions->getCollection()->transform(function (EvidenceSession $session): EvidenceSession {
            $session->setAttribute('evidence_id', $session->evidenceId());

            return $session;
        });

        $payload = $sessions->toArray();
        $payload['stats'] = [
            'total' => EvidenceSession::query()->count(),
            'high' => EvidenceSession::query()->where('risk_level', 'high')->count(),
            'medium' => EvidenceSession::query()->where('risk_level', 'medium')->count(),
            'low' => EvidenceSession::query()->where('risk_level', 'low')->count(),
            'storage' => EvidenceSession::query()->where('status', 'active')->exists()
                ? 'active'
                : 'worm_locked',
        ];

        return response()->json($payload, Response::HTTP_OK, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'X-WORM-Policy' => 'read-only; evidence records are immutable',
        ]);
    }

    /**
     * Generate a realistic finalized emergency session with mock evidence so the
     * review flow can be exercised without live camera capture.
     */
    public function createMockSession(Request $request): JsonResponse
    {
        $genesis = str_repeat('0', 64);

        $session = EvidenceSession::create([
            'user_id' => $request->user()?->id,
            'status' => 'active',
            'risk_level' => 'high',
            'started_at' => now()->subMinutes(6),
        ]);

        $previousCumulative = $genesis;
        $capturedAt = now()->subMinutes(6);

        $indicatorProfiles = [
            [
                'weapon' => 0.41,
                'violence' => 0.33,
                'acoustic_distress' => 0.52,
                'risk_level' => 'low',
                'confidence' => 0.52,
                'reason' => 'Low-level environmental activity recorded. Confidence: 52%',
            ],
            [
                'weapon' => 0.72,
                'violence' => 0.68,
                'acoustic_distress' => 0.60,
                'risk_level' => 'medium',
                'confidence' => 0.72,
                'reason' => 'Possible weapon detected and physical confrontation visible. Confidence: 72%',
            ],
            [
                'weapon' => 0.93,
                'violence' => 0.85,
                'acoustic_distress' => 0.89,
                'risk_level' => 'high',
                'confidence' => 0.93,
                'reason' => 'Possible weapon detected and physical confrontation visible. Confidence: 87%',
            ],
            [
                'weapon' => 0.88,
                'violence' => 0.81,
                'acoustic_distress' => 0.90,
                'risk_level' => 'high',
                'confidence' => 0.90,
                'reason' => 'Possible weapon detected and acoustic distress signatures present. Confidence: 90%',
            ],
        ];

        for ($sequence = 1; $sequence <= 4; $sequence++) {
            $chunkHash = hash('sha256', $session->id.':'.$sequence.':'.bin2hex(random_bytes(16)));
            $cumulativeHash = hash('sha256', $previousCumulative.$chunkHash);

            EvidenceChunk::create([
                'session_id' => $session->id,
                'sequence_number' => $sequence,
                'storage_path' => sprintf('evidence/%s/chunks/%010d.webm', $session->id, $sequence),
                'byte_size' => random_int(180_000, 620_000),
                'chunk_hash' => $chunkHash,
                'cumulative_hash' => $cumulativeHash,
                'latitude' => -1.2921,
                'longitude' => 36.8219,
                'accuracy_meters' => round(random_int(400, 1800) / 100, 2),
                'captured_at' => $capturedAt->copy()->addSeconds($sequence * 3),
                'ai_threat_indicators' => [
                    ...$indicatorProfiles[$sequence - 1],
                    'assessed_at' => now()->toIso8601String(),
                ],
            ]);

            $previousCumulative = $cumulativeHash;
        }

        $session->forceFill([
            'chain_hash' => $previousCumulative,
            'status' => 'finalized',
            'finalized_at' => now(),
        ])->save();

        AuditLog::create([
            'session_id' => $session->id,
            'actor_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'action' => 'session.mock_generated',
        ]);

        return response()->json([
            'session_id' => $session->id,
            'status' => $session->status,
            'risk_level' => $session->risk_level,
            'chunk_count' => 4,
        ], Response::HTTP_CREATED);
    }

    /**
     * Open a new evidence preservation session and mint its UUID.
     */
    public function startSession(Request $request): JsonResponse
    {
        $session = EvidenceSession::create([
            'user_id' => $request->user()?->id,
            'status' => 'active',
            'risk_level' => 'unassessed',
            'started_at' => now(),
        ]);

        AuditLog::create([
            'session_id' => $session->id,
            'actor_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'action' => 'session.started',
        ]);

        return response()->json([
            'session_id' => $session->id,
            'status' => $session->status,
            'started_at' => $session->started_at,
        ], Response::HTTP_CREATED);
    }

    /**
     * Seal a session by transitioning its status to finalized.
     */
    public function finalizeSession(Request $request, string $sessionId): JsonResponse
    {
        $session = EvidenceSession::findOrFail($sessionId);

        if ($session->status === 'finalized') {
            return response()->json([
                'error' => 'WORM Policy: This session has already been finalized and cannot be re-sealed.',
            ], Response::HTTP_CONFLICT);
        }

        $session->forceFill([
            'status' => 'finalized',
            'finalized_at' => now(),
        ])->save();

        AuditLog::create([
            'session_id' => $session->id,
            'actor_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'action' => 'session.finalized',
        ]);

        return response()->json([
            'session_id' => $session->id,
            'status' => $session->status,
            'chain_hash' => $session->chain_hash,
            'finalized_at' => $session->finalized_at,
        ], Response::HTTP_OK);
    }

    /**
     * Return session metadata plus short-lived (5-minute) signed read URLs for
     * each evidence chunk, for authorized previewing without exposing the bucket.
     */
    public function show(Request $request, string $sessionId): JsonResponse
    {
        $session = EvidenceSession::with([
            'chunks' => fn ($query) => $query->orderBy('sequence_number'),
        ])->findOrFail($sessionId);

        $disk = Storage::disk((string) config('filesystems.evidence_disk', 'r2'));
        $expiresAt = now()->addMinutes(5);

        $chunks = $session->chunks->map(function (EvidenceChunk $chunk) use ($disk, $expiresAt, $session): array {
            return $this->serializeChunkPayload($chunk, $session, $disk, $expiresAt);
        })->all();

        return response()->json([
            'session' => [
                'id' => $session->id,
                'evidence_id' => $session->evidenceId(),
                'status' => $session->status,
                'risk_level' => $session->risk_level,
                'chain_hash' => $session->chain_hash,
                'started_at' => $session->started_at,
                'finalized_at' => $session->finalized_at,
                'chunk_count' => count($chunks),
            ],
            'signed_url_expires_at' => $expiresAt,
            'chunks' => $chunks,
        ], Response::HTTP_OK, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'X-WORM-Policy' => 'read-only; evidence records are immutable',
        ]);
    }

    /**
     * Signed sequential playback manifest for continuous player fallback.
     */
    public function playbackManifest(string $sessionId, EvidenceMediaService $media): JsonResponse
    {
        $session = EvidenceSession::findOrFail($sessionId);

        return response()->json($media->getPlaybackManifest($session), Response::HTTP_OK, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'X-WORM-Policy' => 'read-only; evidence records are immutable',
        ]);
    }

    /**
     * Recompute SHA-256 of each stored chunk and the cumulative chain from genesis.
     */
    public function verifyIntegrity(string $sessionId, ForensicReportService $reports): JsonResponse
    {
        $session = EvidenceSession::findOrFail($sessionId);

        $verdict = $reports->verifyIntegrity($session);

        if ($verdict['chain_intact'] !== true) {
            return response()->json($verdict, Response::HTTP_UNPROCESSABLE_ENTITY, [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'X-WORM-Policy' => 'read-only; evidence records are immutable',
            ]);
        }

        return response()->json($verdict, Response::HTTP_OK, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'X-WORM-Policy' => 'read-only; evidence records are immutable',
        ]);
    }

    /**
     * Download a ZIP evidence package (PDF + ledger JSON + hash list).
     */
    public function exportPackage(Request $request, string $sessionId, ForensicReportService $reports): BinaryFileResponse
    {
        $session = EvidenceSession::findOrFail($sessionId);

        AuditLog::create([
            'session_id' => $session->id,
            'actor_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'action' => 'package.exported',
        ]);

        return $reports->exportEvidencePackage($session);
    }

    /**
     * Investigator override of the session risk tier (does not rewrite chunk hashes).
     */
    public function overrideRiskLevel(Request $request, string $sessionId): JsonResponse
    {
        $data = $request->validate([
            'risk_level' => ['required', 'in:high,medium,low'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'override_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $reason = (string) ($data['reason'] ?? $data['override_reason'] ?? 'Investigator amended AI risk assessment.');
        $session = EvidenceSession::query()->findOrFail($sessionId);
        $previousRiskLevel = (string) $session->risk_level;
        $session->update(['risk_level' => $data['risk_level']]);

        AuditLog::create([
            'session_id' => $session->id,
            'actor_ip'   => $request->ip(),
            'user_agent' => $request->userAgent(),
            'action'     => 'risk.manually_amended',
            'metadata'   => [
                'risk_level'  => $data['risk_level'],
                'reason'      => $reason,
                'previous_risk_level' => $previousRiskLevel,
            ],
        ]);

        if ($data['risk_level'] === 'high') {
            try {
                BroadcastTelegramAlertJob::dispatch($session->fresh() ?? $session, null, [
                    'weapon' => 1.0,
                    'violence' => 1.0,
                    'acoustic_distress' => 1.0,
                    'reason' => $reason,
                    'risk_level' => 'high',
                ]);
            } catch (\Throwable) {
                // Telegram dispatch must never block the override response.
            }
        }

        return response()->json([
            'status' => 'success',
            'session' => $session->fresh(),
        ], Response::HTTP_OK, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'X-WORM-Policy' => 'read-only; evidence records are immutable',
        ]);
    }

    /**
     * Alias retained for existing clients.
     */
    public function overrideRisk(Request $request, string $sessionId): JsonResponse
    {
        return $this->overrideRiskLevel($request, $sessionId);
    }

    /**
     * Authenticated media stream for a stored chunk (local-disk / signed-URL fallback).
     */
    public function streamChunk(string $sessionId, int $sequence): Response
    {
        $session = EvidenceSession::findOrFail($sessionId);
        $chunk = EvidenceChunk::query()
            ->where('session_id', $session->id)
            ->where('sequence_number', $sequence)
            ->firstOrFail();

        $disk = Storage::disk((string) config('filesystems.evidence_disk', 'r2'));

        try {
            if (! $disk->exists($chunk->storage_path)) {
                abort(Response::HTTP_NOT_FOUND, 'No binary stream stored for this chunk.');
            }
        } catch (\Throwable) {
            abort(Response::HTTP_NOT_FOUND, 'No binary stream stored for this chunk.');
        }

        $mime = $chunk->mimeType();

        return $disk->response($chunk->storage_path, basename((string) $chunk->storage_path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'X-WORM-Policy' => 'read-only; evidence records are immutable',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeChunkPayload(EvidenceChunk $chunk, EvidenceSession $session, Filesystem $disk, DateTimeInterface $expiresAt): array
    {
        // R2/S3 disks configured with 'throw' => true will propagate on every
        // storage call when credentials are invalid or the object is absent.
        // All three operations (exists, temporaryUrl, url) must be independently
        // guarded so a single failure does not bubble through the entire show().
        $exists = false;

        try {
            $exists = $disk->exists((string) $chunk->storage_path);
        } catch (\Throwable) {
            $exists = false;
        }

        $signedUrl = null;

        if ($exists) {
            try {
                $signedUrl = $disk->temporaryUrl((string) $chunk->storage_path, $expiresAt);
            } catch (\Throwable) {
                $signedUrl = null;
            }
        }

        // Always compute the proxy URL — it works even when R2 is unreachable
        // because it is served by this application via the streamChunk action.
        $proxyUrl = url('/api/v1/evidence/' . $session->id . '/chunks/' . $chunk->sequence_number . '/media');
        $directUrl = null;

        if ($exists) {
            try {
                $directUrl = $disk->url((string) $chunk->storage_path);
            } catch (\Throwable) {
                $directUrl = null;
            }
        }

        // Preference order: signed (expiring) → direct public → authenticated proxy.
        // The proxy URL is always returned as a final fallback so the investigator
        // player always has a URL to attempt, even for mock sessions without binaries.
        $mediaUrl = $signedUrl ?? $directUrl ?? ($exists ? $proxyUrl : $proxyUrl);

        return [
            'sequence_number'     => $chunk->sequence_number,
            'storage_path'        => $chunk->storage_path,
            'byte_size'           => $chunk->byte_size,
            'chunk_hash'          => $chunk->chunk_hash,
            'cumulative_hash'     => $chunk->cumulative_hash,
            'captured_at'         => $chunk->captured_at,
            'latitude'            => $chunk->latitude,
            'longitude'           => $chunk->longitude,
            'accuracy_meters'     => $chunk->accuracy_meters,
            'ai_threat_indicators' => $chunk->ai_threat_indicators,
            'has_binary'          => $exists,
            'signed_url'          => $signedUrl,
            'media_url'           => $mediaUrl,
            'mime_type'           => $chunk->mimeType(),
            'file_type'           => $chunk->fileType(),
        ];
    }
}

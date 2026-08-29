<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\BroadcastTelegramAlertJob;
use App\Jobs\DispatchSmsAlertJob;
use App\Jobs\DispatchVoiceBriefingJob;
use App\Models\AuditLog;
use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use App\Models\User;
use App\Services\EvidenceMediaService;
use App\Services\EvidenceStorageService;
use App\Services\ForensicReportService;
use App\Services\SmsDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class EvidenceSessionController extends Controller
{
    public function __construct(private readonly EvidenceStorageService $storage)
    {
    }
    /**
     * Return paginated evidence sessions with chunk counts, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED);
        $owned = EvidenceSession::query()->ownedBy($user);

        $sessions = (clone $owned)
            ->withCount('chunks')
            ->orderByDesc('created_at')
            ->paginate(15);

        $sessions->getCollection()->transform(function (EvidenceSession $session): EvidenceSession {
            $session->setAttribute('evidence_id', $session->evidenceId());

            return $session;
        });

        $payload = $sessions->toArray();
        $payload['stats'] = [
            'total' => (clone $owned)->count(),
            'high' => (clone $owned)->where('risk_level', 'high')->count(),
            'medium' => (clone $owned)->where('risk_level', 'medium')->count(),
            'low' => (clone $owned)->where('risk_level', 'low')->count(),
            'storage' => (clone $owned)->where('status', 'active')->exists()
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
        $session = EvidenceSession::findOwnedOrFail($sessionId, $request->user());

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
        ])->whereKey($sessionId)->ownedBy($request->user())->firstOrFail();

        $expiresAt = now()->addMinutes(5);

        $chunks = $session->chunks->map(function (EvidenceChunk $chunk) use ($session): array {
            return $this->serializeChunkPayload($chunk, $session);
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
    public function playbackManifest(Request $request, string $sessionId, EvidenceMediaService $media): JsonResponse
    {
        $session = EvidenceSession::findOwnedOrFail($sessionId, $request->user());

        return response()->json($media->getPlaybackManifest($session), Response::HTTP_OK, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'X-WORM-Policy' => 'read-only; evidence records are immutable',
        ]);
    }

    /**
     * Recompute SHA-256 of each stored chunk and the cumulative chain from genesis.
     */
    public function verifyIntegrity(Request $request, string $sessionId, ForensicReportService $reports): JsonResponse
    {
        $session = EvidenceSession::findOwnedOrFail($sessionId, $request->user());

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
        $session = EvidenceSession::findOwnedOrFail($sessionId, $request->user());

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
        $session = EvidenceSession::findOwnedOrFail($sessionId, $request->user());
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
            $session->loadMissing('chunks');
            $chunk = $session->chunks->sortByDesc('sequence_number')->first();
            $indicators = [
                'weapon' => 1.0,
                'violence' => 1.0,
                'acoustic_distress' => 1.0,
                'reason' => $reason,
                'risk_level' => 'high',
            ];

            try {
                BroadcastTelegramAlertJob::dispatch($session->fresh() ?? $session, $chunk, $indicators);
            } catch (\Throwable) {
                // Telegram dispatch must never block the override response.
            }

            if ($chunk !== null) {
                try {
                    DispatchVoiceBriefingJob::dispatch($session, $chunk, $indicators);
                } catch (\Throwable) {
                    // Voice briefing is best-effort.
                }

                try {
                    DispatchSmsAlertJob::dispatch($session, $chunk);
                } catch (\Throwable) {
                    // SMS is best-effort.
                }
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
     * Authenticated media stream for a stored chunk (local replica, then cloud).
     */
    public function streamChunk(Request $request, string $sessionId, int $sequence): Response
    {
        $session = EvidenceSession::findOwnedOrFail($sessionId, $request->user());
        $chunk = EvidenceChunk::query()
            ->where('session_id', $session->id)
            ->where('sequence_number', $sequence)
            ->firstOrFail();

        $path = (string) $chunk->storage_path;

        if ($path === '' || ! $this->storage->exists($path)) {
            abort(Response::HTTP_NOT_FOUND, 'No binary stream stored for this chunk.');
        }

        return $this->storage->stream($path, basename($path), $chunk->mimeType());
    }

    /**
     * One-time SMS access code: confirms the incident without issuing a vault token.
     */
    public function redeemEmergencyAccess(Request $request, SmsDispatchService $sms): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:16'],
        ]);

        $payload = $sms->redeemToken((string) $data['code']);

        if ($payload === null) {
            return response()->json([
                'error' => 'Invalid or expired access code.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $session = EvidenceSession::query()->find($payload['session_id'] ?? null);

        if ($session === null) {
            return response()->json([
                'error' => 'Invalid or expired access code.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $chunk = $session->chunks()->orderByDesc('sequence_number')->first();

        return response()->json([
            'ok' => true,
            'evidence_id' => $session->evidenceId(),
            'session_id' => $session->id,
            'risk_level' => $session->risk_level,
            'gps' => [
                'latitude' => $chunk?->latitude,
                'longitude' => $chunk?->longitude,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeChunkPayload(EvidenceChunk $chunk, EvidenceSession $session): array
    {
        $proxyUrl = url('/api/v1/evidence/'.$session->id.'/chunks/'.$chunk->sequence_number.'/media');

        return [
            'sequence_number' => $chunk->sequence_number,
            'storage_path' => $chunk->storage_path,
            'byte_size' => $chunk->byte_size,
            'chunk_hash' => $chunk->chunk_hash,
            'cumulative_hash' => $chunk->cumulative_hash,
            'captured_at' => $chunk->captured_at,
            'latitude' => $chunk->latitude,
            'longitude' => $chunk->longitude,
            'accuracy_meters' => $chunk->accuracy_meters,
            'ai_threat_indicators' => $chunk->ai_threat_indicators,
            'has_binary' => $this->storage->exists((string) $chunk->storage_path),
            'signed_url' => null,
            'media_url' => $proxyUrl,
            'mime_type' => $chunk->mimeType(),
            'file_type' => $chunk->fileType(),
        ];
    }
}

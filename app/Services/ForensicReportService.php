<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvidenceSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class ForensicReportService
{
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private const EAT_TIMEZONE = 'Africa/Nairobi';

    public function __construct(private readonly EvidenceStorageService $storage)
    {
    }

    /**
     * Independently recompute each chunk SHA-256 (from stored bytes when available)
     * and the cumulative hash chain from genesis. Returns a verdict payload.
     *
     * @return array{status: string, chain_intact: bool, chunks_verified: int, verified_at: string, tampered_at?: int, reason?: string, ledger: list<array<string, mixed>>}
     */
    public function verifyIntegrity(EvidenceSession $session): array
    {
        $session->loadMissing(['chunks' => fn ($query) => $query->orderBy('sequence_number')]);

        $previous = self::GENESIS_HASH;
        $ledger = [];
        $tamperedAt = null;
        $reason = null;
        $verified = 0;

        foreach ($session->chunks as $chunk) {
            $byteHash = $this->hashStoredChunk((string) $chunk->storage_path);
            $bytesMatch = $byteHash === null || hash_equals($byteHash, (string) $chunk->chunk_hash);
            $expectedCumulative = hash('sha256', $previous.$chunk->chunk_hash);
            $chainMatch = hash_equals($expectedCumulative, (string) $chunk->cumulative_hash);

            $rowOk = $bytesMatch && $chainMatch;

            if (! $rowOk && $tamperedAt === null) {
                $tamperedAt = (int) $chunk->sequence_number;
                $reason = ! $bytesMatch
                    ? sprintf('Stored object SHA-256 mismatch at chunk #%d', $chunk->sequence_number)
                    : sprintf('Cumulative chain break at chunk #%d', $chunk->sequence_number);
            }

            if ($rowOk) {
                $verified++;
            }

            $ledger[] = [
                'sequence' => $chunk->sequence_number,
                'chunk_hash' => $chunk->chunk_hash,
                'computed_byte_hash' => $byteHash,
                'bytes_verified' => $bytesMatch,
                'cumulative_hash' => $chunk->cumulative_hash,
                'expected_cumulative_hash' => $expectedCumulative,
                'chain_verified' => $chainMatch,
            ];

            $previous = (string) $chunk->cumulative_hash;
        }

        $finalOk = $tamperedAt === null
            && hash_equals($previous, (string) ($session->chain_hash ?? self::GENESIS_HASH));

        if ($finalOk) {
            return [
                'status' => 'VERIFIED',
                'chain_intact' => true,
                'chunks_verified' => $verified,
                'verified_at' => now()->timezone(self::EAT_TIMEZONE)->toIso8601String(),
                'ledger' => $ledger,
            ];
        }

        return [
            'status' => 'TAMPER_DETECTED',
            'chain_intact' => false,
            'chunks_verified' => $verified,
            'tampered_at' => $tamperedAt,
            'reason' => $reason ?? 'Session chain_hash does not match the recomputed tip.',
            'verified_at' => now()->timezone(self::EAT_TIMEZONE)->toIso8601String(),
            'ledger' => $ledger,
        ];
    }

    /**
     * Stream a ZIP evidence package: report.pdf, ledger.json, hashes.txt.
     */
    public function exportEvidencePackage(EvidenceSession $session): BinaryFileResponse
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The ZipArchive PHP extension is required to export evidence packages.');
        }

        $session->loadMissing(['chunks' => fn ($query) => $query->orderBy('sequence_number')]);

        $evidenceId = $session->evidenceId();
        $pdf = $this->generateReport($session);

        $chunks = $session->chunks->map(static fn ($chunk): array => [
            'sequence_number' => $chunk->sequence_number,
            'storage_path' => $chunk->storage_path,
            'byte_size' => $chunk->byte_size,
            'chunk_hash' => $chunk->chunk_hash,
            'cumulative_hash' => $chunk->cumulative_hash,
            'latitude' => $chunk->latitude,
            'longitude' => $chunk->longitude,
            'accuracy_meters' => $chunk->accuracy_meters,
            'captured_at' => $chunk->captured_at,
            'ai_threat_indicators' => $chunk->ai_threat_indicators,
        ])->all();

        $ledger = [
            'evidence_id' => $evidenceId,
            'session_id' => $session->id,
            'status' => $session->status,
            'risk_level' => $session->risk_level,
            'chain_hash' => $session->chain_hash,
            'started_at' => $session->started_at,
            'finalized_at' => $session->finalized_at,
            'chunks' => $chunks,
        ];

        $hashLines = [
            "ProofVault SHA-256 ledger — {$evidenceId}",
            'Genesis: '.self::GENESIS_HASH,
            '',
        ];
        foreach ($session->chunks as $chunk) {
            $hashLines[] = sprintf('#%03d  %s', $chunk->sequence_number, $chunk->chunk_hash);
        }
        $hashLines[] = '';
        $hashLines[] = 'chain_hash  '.($session->chain_hash ?? self::GENESIS_HASH);

        $tmp = tempnam(sys_get_temp_dir(), 'pvpkg_');
        $zipPath = ($tmp !== false ? $tmp : sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('pvpkg_', true)).'.zip';
        if ($tmp !== false) {
            @unlink($tmp);
        }
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the evidence package archive.');
        }

        $prefix = $evidenceId.'/';
        $zip->addFromString($prefix.'report.pdf', $pdf);
        $zip->addFromString($prefix.'ledger.json', json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        $zip->addFromString($prefix.'hashes.txt', implode("\n", $hashLines)."\n");

        $stitched = app(EvidenceMediaService::class)->stitchToMp4($session);
        if ($stitched !== null && is_file($stitched)) {
            $zip->addFile($stitched, $prefix.'evidence.mp4');
        }

        $zip->close();

        if ($stitched !== null) {
            @unlink($stitched);
            @rmdir(dirname($stitched));
        }

        return response()
            ->download($zipPath, $evidenceId.'-evidence-package.zip', [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'X-WORM-Policy' => 'read-only; evidence records are immutable',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * Stream-hash an object on the evidence disk. Returns null when the object is absent
     * (e.g. mock sessions) so chain verification can still proceed on stored hashes.
     */
    private function hashStoredChunk(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $stream = $this->storage->readStream($path);

        if ($stream === false) {
            return null;
        }

        try {
            $context = hash_init('sha256');

            try {
                while (! feof($stream)) {
                    $buffer = fread($stream, 8192);

                    if ($buffer === false || $buffer === '') {
                        continue;
                    }

                    hash_update($context, $buffer);
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return hash_final($context);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Independently recompute the SHA-256 cumulative chain and return a
     * verification verdict plus per-row ledger.
     *
     * @return array{ok: bool, broken_at: int|null, evidence_id: string, chain_hash: string, ledger: list<array<string, mixed>>}
     */
    public function verifyChain(EvidenceSession $session): array
    {
        $session->loadMissing(['chunks' => fn ($query) => $query->orderBy('sequence_number')]);

        $previous = self::GENESIS_HASH;
        $ledger = [];
        $brokenAt = null;

        foreach ($session->chunks as $chunk) {
            $expected = hash('sha256', $previous.$chunk->chunk_hash);
            $verified = hash_equals($expected, (string) $chunk->cumulative_hash);

            if (! $verified && $brokenAt === null) {
                $brokenAt = (int) $chunk->sequence_number;
            }

            $ledger[] = [
                'sequence' => $chunk->sequence_number,
                'chunk_hash' => $chunk->chunk_hash,
                'cumulative_hash' => $chunk->cumulative_hash,
                'expected_cumulative_hash' => $expected,
                'verified' => $verified,
            ];

            $previous = (string) $chunk->cumulative_hash;
        }

        $finalOk = $brokenAt === null
            && hash_equals($previous, (string) ($session->chain_hash ?? self::GENESIS_HASH));

        return [
            'ok' => $finalOk,
            'broken_at' => $brokenAt,
            'evidence_id' => $session->evidenceId(),
            'chain_hash' => (string) ($session->chain_hash ?? self::GENESIS_HASH),
            'ledger' => $ledger,
        ];
    }

    /**
     * Compile a formal forensic chain-of-custody PDF for the session and return
     * the rendered PDF as a binary string.
     */
    public function generateReport(EvidenceSession $session): string
    {
        $session->loadMissing(['chunks' => fn ($query) => $query->orderBy('sequence_number'), 'auditLogs', 'user']);

        $chunks = $session->chunks;

        // Recompute the cumulative chain independently to prove ledger integrity.
        $previous = self::GENESIS_HASH;
        $ledger = [];

        foreach ($chunks as $chunk) {
            $expected = hash('sha256', $previous.$chunk->chunk_hash);

            $ledger[] = [
                'sequence' => $chunk->sequence_number,
                'captured_at' => $this->eat($chunk->captured_at),
                'byte_size' => number_format((int) $chunk->byte_size),
                'chunk_hash' => $chunk->chunk_hash,
                'cumulative_hash' => $chunk->cumulative_hash,
                'verified' => hash_equals($expected, (string) $chunk->cumulative_hash),
                'latitude' => $chunk->latitude,
                'longitude' => $chunk->longitude,
                'accuracy_meters' => $chunk->accuracy_meters,
                'ai_indicators' => $chunk->ai_threat_indicators,
            ];

            $previous = (string) $chunk->cumulative_hash;
        }

        $chainVerified = collect($ledger)->every(fn (array $row): bool => $row['verified'])
            && hash_equals($previous, (string) ($session->chain_hash ?? self::GENESIS_HASH));

        $data = [
            'session' => $session,
            'ledger' => $ledger,
            'auditLogs' => $session->auditLogs,
            'chainVerified' => $chainVerified,
            'finalChainHash' => $session->chain_hash ?? self::GENESIS_HASH,
            'startedAt' => $this->eat($session->started_at),
            'finalizedAt' => $this->eat($session->finalized_at),
            'generatedAt' => $this->eat(now()),
            'investigatorName' => $session->user?->name ?: 'Unknown investigator',
        ];

        return Pdf::loadView('reports.forensic', $data)
            ->setPaper('a4', 'portrait')
            ->output();
    }

    private function eat(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return Carbon::parse($value)->timezone(self::EAT_TIMEZONE)->format('Y-m-d H:i:s').' EAT';
    }
}

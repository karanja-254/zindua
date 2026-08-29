<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

class EvidenceMediaService
{
    /**
     * Build a playback manifest of short-lived signed URLs in capture order.
     *
     * @return array{session_id: string, evidence_id: string, ffmpeg_available: bool, items: list<array<string, mixed>>}
     */
    public function getPlaybackManifest(EvidenceSession $session): array
    {
        $session->loadMissing(['chunks' => fn ($query) => $query->orderBy('sequence_number')]);

        $disk = Storage::disk((string) config('filesystems.evidence_disk', 's3'));
        $expiresAt = now()->addMinutes(5);

        $items = $session->chunks->map(function (EvidenceChunk $chunk) use ($disk, $expiresAt): array {
            $url = null;

            try {
                $url = $disk->temporaryUrl($chunk->storage_path, $expiresAt);
            } catch (\Throwable) {
                $url = null;
            }

            return [
                'sequence_number' => $chunk->sequence_number,
                'url' => $url,
                'storage_path' => $chunk->storage_path,
                'byte_size' => $chunk->byte_size,
                'chunk_hash' => $chunk->chunk_hash,
                'captured_at' => $chunk->captured_at,
            ];
        })->values()->all();

        return [
            'session_id' => (string) $session->id,
            'evidence_id' => $session->evidenceId(),
            'ffmpeg_available' => $this->ffmpegBinary() !== null,
            'signed_url_expires_at' => $expiresAt->toIso8601String(),
            'items' => $items,
        ];
    }

    /**
     * Concatenate stored chunks into a single MP4 when ffmpeg is on PATH.
     * Returns the local temp path, or null if stitch is unavailable/failed.
     */
    public function stitchToMp4(EvidenceSession $session): ?string
    {
        $ffmpeg = $this->ffmpegBinary();

        if ($ffmpeg === null) {
            return null;
        }

        $session->loadMissing(['chunks' => fn ($query) => $query->orderBy('sequence_number')]);
        $disk = Storage::disk((string) config('filesystems.evidence_disk', 's3'));

        $workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pvstitch_'.bin2hex(random_bytes(6));

        if (! mkdir($workDir, 0700, true) && ! is_dir($workDir)) {
            return null;
        }

        $listPath = $workDir.DIRECTORY_SEPARATOR.'concat.txt';
        $outputPath = $workDir.DIRECTORY_SEPARATOR.'evidence.mp4';
        $lines = [];
        $copied = 0;

        try {
            foreach ($session->chunks as $chunk) {
                $path = (string) $chunk->storage_path;

                if ($path === '') {
                    continue;
                }

                try {
                    if (! $disk->exists($path)) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }

                $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'bin';
                $local = sprintf('%s%schunk_%03d.%s', $workDir, DIRECTORY_SEPARATOR, $chunk->sequence_number, $ext);

                try {
                    $contents = $disk->get($path);
                } catch (\Throwable) {
                    continue;
                }

                if ($contents === null || $contents === '') {
                    continue;
                }

                file_put_contents($local, $contents);
                $posix = str_replace('\\', '/', $local);
                $lines[] = 'file '.json_encode($posix);
                $copied++;
            }

            if ($copied < 1) {
                return null;
            }

            file_put_contents($listPath, implode("\n", $lines)."\n");

            $copy = Process::timeout(180)->run([
                $ffmpeg,
                '-y',
                '-f', 'concat',
                '-safe', '0',
                '-i', $listPath,
                '-c', 'copy',
                $outputPath,
            ]);

            if (! $copy->successful() || ! is_file($outputPath) || filesize($outputPath) === 0) {
                @unlink($outputPath);

                $reencode = Process::timeout(300)->run([
                    $ffmpeg,
                    '-y',
                    '-f', 'concat',
                    '-safe', '0',
                    '-i', $listPath,
                    '-c:v', 'libx264',
                    '-c:a', 'aac',
                    '-movflags', '+faststart',
                    $outputPath,
                ]);

                if (! $reencode->successful() || ! is_file($outputPath) || filesize($outputPath) === 0) {
                    return null;
                }
            }

            return $outputPath;
        } finally {
            // Keep output; caller deletes. Clean concat inputs after stitch attempt.
            foreach (glob($workDir.DIRECTORY_SEPARATOR.'chunk_*') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($listPath);
        }
    }

    private function ffmpegBinary(): ?string
    {
        $configured = config('services.ffmpeg_path');

        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }

        $finder = new ExecutableFinder();

        return $finder->find('ffmpeg');
    }
}

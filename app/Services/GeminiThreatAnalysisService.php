<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvidenceChunk;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

class GeminiThreatAnalysisService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';

    private const PROMPT = 'Analyze this image, video frame, or audio snippet for emergency safety: detect weapons, physical violence, fire, or distress. Return JSON with \'risk_level\' (\'high\', \'medium\', or \'low\'), \'confidence\' (0.0 to 1.0), and \'reason\' (concise description).';

    public function __construct(private readonly EvidenceStorageService $storage)
    {
    }

    /**
     * Send a captured frame/snapshot to Gemini and return a structured risk evaluation.
     *
     * @return array{risk_level: string, confidence: float, reason: string, weapon: float, violence: float, acoustic_distress: float, source: string}|null
     */
    public function analyzeChunk(EvidenceChunk $chunk): ?array
    {
        $key = config('services.gemini.api_key') ?: config('services.gemini.key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        $frame = $this->extractMediaPayload($chunk);

        if ($frame === null) {
            return null;
        }

        try {
            $response = Http::timeout(45)
                ->acceptJson()
                ->asJson()
                ->post(self::ENDPOINT.'?key='.$key, [
                    'contents' => [[
                        'parts' => [
                            ['text' => self::PROMPT],
                            [
                                'inline_data' => [
                                    'mime_type' => $frame['mime'],
                                    'data' => base64_encode($frame['bytes']),
                                ],
                            ],
                        ],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'responseMimeType' => 'application/json',
                    ],
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Gemini threat analysis request failed.', [
                'chunk_id' => $chunk->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Gemini threat analysis returned a non-success status.', [
                'chunk_id' => $chunk->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');

        return $this->parseEvaluation($text);
    }

    /**
     * @return array{risk_level: string, confidence: float, reason: string, weapon: float, violence: float, acoustic_distress: float, source: string}|null
     */
    private function parseEvaluation(string $text): ?array
    {
        $trimmed = trim($text);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $trimmed, $matches) === 1) {
            $trimmed = trim($matches[1]);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $risk = strtolower((string) ($decoded['risk_level'] ?? ''));

        if (! in_array($risk, ['high', 'medium', 'low'], true)) {
            return null;
        }

        $confidence = max(0.0, min(1.0, (float) ($decoded['confidence'] ?? 0)));
        $reason = trim((string) ($decoded['reason'] ?? ''));

        if ($reason === '') {
            $reason = 'Gemini emergency-safety evaluation completed.';
        }

        $lower = strtolower($reason);
        $weapon = $this->scoreFromReason($lower, ['weapon', 'gun', 'knife', 'firearm', 'blade'], $risk, $confidence);
        $violence = $this->scoreFromReason($lower, ['violence', 'assault', 'fight', 'confrontation', 'attack'], $risk, $confidence);
        $distress = $this->scoreFromReason($lower, ['distress', 'fire', 'scream', 'help', 'panic'], $risk, $confidence);

        $floor = match ($risk) {
            'high' => $confidence,
            'medium' => round($confidence * 0.7, 4),
            default => round($confidence * 0.3, 4),
        };

        return [
            'risk_level' => $risk,
            'confidence' => $confidence,
            'reason' => $reason,
            'weapon' => max($weapon, $floor),
            'violence' => max($violence, $floor),
            'acoustic_distress' => max($distress, $floor),
            'source' => 'gemini',
        ];
    }

    /**
     * @param  list<string>  $needles
     */
    private function scoreFromReason(string $reason, array $needles, string $risk, float $confidence): float
    {
        foreach ($needles as $needle) {
            if (str_contains($reason, $needle)) {
                return $confidence;
            }
        }

        return match ($risk) {
            'high' => round($confidence * 0.85, 4),
            'medium' => round($confidence * 0.55, 4),
            default => round($confidence * 0.2, 4),
        };
    }

    /**
     * Fetch the stored object from R2/S3 and return an image frame, audio snippet, or still.
     *
     * @return array{mime: string, bytes: string}|null
     */
    private function extractMediaPayload(EvidenceChunk $chunk): ?array
    {
        $path = (string) $chunk->storage_path;

        if ($path === '') {
            return null;
        }

        $stream = $this->storage->readStream($path);

        if ($stream === false) {
            return null;
        }

        try {
            $header = (string) fread($stream, 64);
            $imageMime = $this->detectImageMime($header);

            if ($imageMime !== null) {
                $rest = (string) stream_get_contents($stream);

                return ['mime' => $imageMime, 'bytes' => $header.$rest];
            }

            $audioMime = $this->detectAudioMime($header, (string) $chunk->storage_path);

            if ($audioMime !== null) {
                $rest = (string) stream_get_contents($stream);
                $bytes = $header.$rest;
                if (strlen($bytes) > 4_000_000) {
                    $bytes = substr($bytes, 0, 4_000_000);
                }

                return ['mime' => $audioMime, 'bytes' => $bytes];
            }

            return $this->extractVideoFrame($header, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param  resource  $stream
     * @return array{mime: string, bytes: string}|null
     */
    private function extractVideoFrame(string $header, $stream): ?array
    {
        $ffmpeg = $this->ffmpegBinary();

        if ($ffmpeg === null) {
            return null;
        }

        $workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pvgemini_'.bin2hex(random_bytes(6));

        if (! mkdir($workDir, 0700, true) && ! is_dir($workDir)) {
            return null;
        }

        $input = $workDir.DIRECTORY_SEPARATOR.'chunk.bin';
        $output = $workDir.DIRECTORY_SEPARATOR.'frame.jpg';

        try {
            $handle = fopen($input, 'wb');

            if ($handle === false) {
                return null;
            }

            fwrite($handle, $header);
            stream_copy_to_stream($stream, $handle);
            fclose($handle);

            $result = Process::timeout(30)->run([
                $ffmpeg,
                '-y',
                '-i', $input,
                '-frames:v', '1',
                '-q:v', '3',
                $output,
            ]);

            if (! $result->successful() || ! is_file($output) || filesize($output) === 0) {
                return null;
            }

            $bytes = file_get_contents($output);

            if ($bytes === false || $bytes === '') {
                return null;
            }

            return ['mime' => 'image/jpeg', 'bytes' => $bytes];
        } finally {
            foreach (glob($workDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($workDir);
        }
    }

    private function detectImageMime(string $header): ?string
    {
        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($header, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }

        if (str_starts_with($header, 'RIFF') && str_contains(substr($header, 0, 16), 'WEBP')) {
            return 'image/webp';
        }

        return null;
    }

    private function detectAudioMime(string $header, string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'], true)) {
            return match ($extension) {
                'mp3' => 'audio/mpeg',
                'wav' => 'audio/wav',
                'ogg' => 'audio/ogg',
                'm4a', 'aac' => 'audio/mp4',
                'flac' => 'audio/flac',
                default => 'audio/mpeg',
            };
        }

        if (str_starts_with($header, 'ID3') || (strlen($header) >= 2 && $header[0] === "\xFF" && (ord($header[1]) & 0xE0) === 0xE0)) {
            return 'audio/mpeg';
        }

        if (str_starts_with($header, 'RIFF') && str_contains(substr($header, 0, 16), 'WAVE')) {
            return 'audio/wav';
        }

        if (str_starts_with($header, 'OggS')) {
            return 'audio/ogg';
        }

        return null;
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

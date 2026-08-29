<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ElevenLabsDispatchService
{
    private const API_BASE = 'https://api.elevenlabs.io/v1/text-to-speech';

    private const EAT_TIMEZONE = 'Africa/Nairobi';

    public function __construct(private readonly EvidenceStorageService $storage)
    {
    }

    /**
     * Synthesize a concise emergency voice briefing via ElevenLabs TTS and persist
     * the resulting MP3 to durable storage.
     *
     * @param  array{weapon: float, violence: float, acoustic_distress: float}  $aiIndicators
     * @return string|null  The S3 storage path of the saved MP3, or null on failure.
     */
    public function generateVoiceBriefing(EvidenceSession $session, EvidenceChunk $chunk, array $aiIndicators): ?string
    {
        $apiKey = config('services.elevenlabs.api_key');
        $voiceId = config('services.elevenlabs.voice_id');

        if (! is_string($apiKey) || trim($apiKey) === '' || ! is_string($voiceId) || trim($voiceId) === '') {
            Log::info('ElevenLabs briefing skipped: ELEVENLABS_API_KEY or ELEVENLABS_VOICE_ID is empty.', [
                'session_id' => $session->id,
            ]);

            return null;
        }

        $script = $this->buildScript($session, $chunk, $aiIndicators);

        try {
            $response = Http::withHeaders([
                'xi-api-key' => $apiKey,
                'Accept' => 'audio/mpeg',
            ])
                ->timeout(45)
                ->post(sprintf('%s/%s', self::API_BASE, $voiceId), [
                    'text' => $script,
                    'model_id' => config('services.elevenlabs.model_id', 'eleven_multilingual_v2'),
                    'voice_settings' => [
                        'stability' => 0.5,
                        'similarity_boost' => 0.75,
                    ],
                ]);
        } catch (\Throwable $exception) {
            Log::error('ElevenLabs TTS synthesis failed.', [
                'session_id' => $session->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::error('ElevenLabs TTS synthesis failed.', [
                'session_id' => $session->id,
                'status' => $response->status(),
            ]);

            return null;
        }

        $storagePath = sprintf('evidence/%s/briefings/%010d.mp3', $session->id, $chunk->sequence_number);

        try {
            $saved = $this->storage->put($storagePath, $response->body());
        } catch (\Throwable $exception) {
            Log::error('Failed to persist ElevenLabs briefing to storage.', [
                'session_id' => $session->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($saved === false) {
            Log::error('Failed to persist ElevenLabs briefing to storage.', [
                'session_id' => $session->id,
            ]);

            return null;
        }

        return $storagePath;
    }

    /**
     * Compose the spoken emergency script (EAT timestamp, coordinates, threat levels).
     *
     * @param  array{weapon: float, violence: float, acoustic_distress: float}  $aiIndicators
     */
    private function buildScript(EvidenceSession $session, EvidenceChunk $chunk, array $aiIndicators): string
    {
        $timestamp = Carbon::parse($chunk->captured_at)
            ->timezone(self::EAT_TIMEZONE)
            ->format('l, F j Y \a\t H:i');

        $location = ($chunk->latitude !== null && $chunk->longitude !== null)
            ? sprintf('latitude %s, longitude %s', $chunk->latitude, $chunk->longitude)
            : 'an undisclosed location';

        $weapon = $this->asPercent($aiIndicators['weapon'] ?? 0.0);
        $violence = $this->asPercent($aiIndicators['violence'] ?? 0.0);
        $distress = $this->asPercent($aiIndicators['acoustic_distress'] ?? 0.0);

        return sprintf(
            'ProofVault emergency briefing. A %s risk incident was detected on %s East Africa Time, at %s. '
            .'Threat assessment indicators are as follows. Weapon detection %s. Violence %s. Acoustic distress %s. '
            .'Immediate response is advised. Evidence has been secured to the encrypted ledger.',
            strtolower((string) $session->risk_level),
            $timestamp,
            $location,
            $weapon,
            $violence,
            $distress,
        );
    }

    private function asPercent(float $score): string
    {
        return number_format($score * 100, 0).' percent';
    }
}

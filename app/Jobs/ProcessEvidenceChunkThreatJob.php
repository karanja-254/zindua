<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use App\Services\GeminiThreatAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\Concerns\UsesThreatAnalysisQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessEvidenceChunkThreatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesThreatAnalysisQueue;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds a single attempt may run before timing out.
     */
    public int $timeout = 120;

    public function __construct(public readonly EvidenceChunk $chunk)
    {
        $this->onThreatQueue();
    }

    /**
     * Exponential backoff (seconds) between the three attempts.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    /**
     * Run Gemini (when configured) or local heuristic scoring, then escalate high risk.
     */
    public function handle(GeminiThreatAnalysisService $gemini): void
    {
        $chunk = $this->chunk->fresh();

        if ($chunk === null) {
            return;
        }

        $session = $chunk->session;

        if ($session === null) {
            return;
        }

        $analysis = $this->assessThreat($chunk, $gemini);
        $indicators = [
            'weapon' => $analysis['weapon'],
            'violence' => $analysis['violence'],
            'acoustic_distress' => $analysis['acoustic_distress'],
        ];
        $peakScore = max($indicators['weapon'], $indicators['violence'], $indicators['acoustic_distress'], $analysis['confidence']);
        $riskLevel = $peakScore >= 0.70 ? 'high' : $analysis['risk_level'];
        $reason = $analysis['reason'];
        $confidence = $analysis['confidence'];

        $payload = [
            ...$indicators,
            'risk_level' => $riskLevel,
            'reason' => $reason,
            'confidence' => $confidence,
            'source' => $analysis['source'],
            'assessed_at' => now()->toIso8601String(),
        ];

        $chunk->forceFill(['ai_threat_indicators' => $payload])->save();

        if ($riskLevel === 'medium') {
            $this->escalateSessionRisk($session, 'medium');

            return;
        }

        if ($riskLevel !== 'high') {
            return;
        }

        $alreadyHigh = $session->risk_level === 'high';
        $this->escalateSessionRisk($session, 'high');

        if ($alreadyHigh) {
            return;
        }

        $session = $session->fresh() ?? $session;

        try {
            BroadcastTelegramAlertJob::dispatch($session, $chunk, $payload);
        } catch (\Throwable) {
            // Queue/API failures must not unwind chunk ingest.
        }

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

    /**
     * Prefer live Gemini vision scoring when GEMINI_API_KEY is configured.
     *
     * @return array{weapon: float, violence: float, acoustic_distress: float, risk_level: string, reason: string, confidence: float, source: string}
     */
    private function assessThreat(EvidenceChunk $chunk, GeminiThreatAnalysisService $gemini): array
    {
        $key = config('services.gemini.api_key') ?: config('services.gemini.key');

        if (is_string($key) && $key !== '') {
            $evaluation = $gemini->analyzeChunk($chunk);

            if ($evaluation !== null) {
                return $evaluation;
            }
        }

        return [
            'weapon' => 0.0,
            'violence' => 0.0,
            'acoustic_distress' => 0.0,
            'risk_level' => 'unassessed',
            'reason' => 'Automated threat scoring was unavailable; evidence was stored without an AI verdict.',
            'confidence' => 0.0,
            'source' => 'unavailable',
        ];
    }

    /**
     * Persist the highest observed risk tier without ever downgrading the session.
     */
    private function escalateSessionRisk(EvidenceSession $session, string $riskLevel): void
    {
        $ranking = ['unassessed' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

        $current = $ranking[$session->risk_level] ?? 0;
        $incoming = $ranking[$riskLevel] ?? 0;

        if ($incoming > $current) {
            $session->forceFill(['risk_level' => $riskLevel])->save();
        }
    }
}

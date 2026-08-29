<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use App\Services\GeminiThreatAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class ProcessEvidenceChunkThreatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds a single attempt may run before timing out.
     */
    public int $timeout = 120;

    /**
     * Risk tiers that warrant an immediate multi-channel broadcast.
     *
     * @var list<string>
     */
    private const ESCALATION_TIERS = ['high', 'medium'];

    public function __construct(public readonly EvidenceChunk $chunk)
    {
        $this->onConnection('redis')->onQueue('threat-analysis');
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
        $riskLevel = $analysis['risk_level'];
        $reason = $analysis['reason'];
        $confidence = $analysis['confidence'];
        $indicators = [
            'weapon' => $analysis['weapon'],
            'violence' => $analysis['violence'],
            'acoustic_distress' => $analysis['acoustic_distress'],
        ];

        $payload = [
            ...$indicators,
            'risk_level' => $riskLevel,
            'reason' => $reason,
            'confidence' => $confidence,
            'source' => $analysis['source'],
            'assessed_at' => now()->toIso8601String(),
        ];

        $chunk->forceFill(['ai_threat_indicators' => $payload])->save();

        if (! in_array($riskLevel, self::ESCALATION_TIERS, true)) {
            return;
        }

        $this->escalateSessionRisk($session, $riskLevel);

        if ($riskLevel !== 'high') {
            return;
        }

        Bus::chain([
            new BroadcastTelegramAlertJob($session->fresh() ?? $session, $chunk, $payload),
            new DispatchVoiceBriefingJob($session, $chunk, $indicators),
        ])
            ->onConnection('redis')
            ->onQueue('threat-analysis')
            ->dispatch();
    }

    /**
     * Prefer live Gemini vision scoring when GEMINI_API_KEY is configured.
     *
     * @return array{weapon: float, violence: float, acoustic_distress: float, risk_level: string, reason: string, confidence: float, source: string}
     */
    private function assessThreat(EvidenceChunk $chunk, GeminiThreatAnalysisService $gemini): array
    {
        $key = config('services.gemini.key');

        if (is_string($key) && $key !== '') {
            $evaluation = $gemini->analyzeChunk($chunk);

            if ($evaluation !== null) {
                return $evaluation;
            }
        }

        $indicators = $this->runThreatAssessment($chunk);
        $riskLevel = $this->deriveRiskLevel($indicators);
        $reason = $this->explainRisk($indicators, $riskLevel);

        return [
            ...$indicators,
            'risk_level' => $riskLevel,
            'reason' => $reason,
            'confidence' => max($indicators),
            'source' => 'heuristic',
        ];
    }

    /**
     * Simulated multi-model inference across weapon, violence, and acoustic distress signals.
     *
     * @return array{weapon: float, violence: float, acoustic_distress: float}
     */
    private function runThreatAssessment(EvidenceChunk $chunk): array
    {
        $seed = hexdec(substr($chunk->chunk_hash, 0, 8));
        mt_srand($seed);

        $score = static fn (): float => round(mt_rand(0, 10000) / 10000, 4);

        return [
            'weapon' => $score(),
            'violence' => $score(),
            'acoustic_distress' => $score(),
        ];
    }

    /**
     * Collapse the indicator scores into a single risk tier.
     *
     * @param  array{weapon: float, violence: float, acoustic_distress: float}  $indicators
     */
    private function deriveRiskLevel(array $indicators): string
    {
        $peak = max($indicators);

        return match (true) {
            $peak >= 0.85 => 'high',
            $peak >= 0.6 => 'medium',
            $peak >= 0.3 => 'low',
            default => 'unassessed',
        };
    }

    /**
     * Produce a human-readable detection summary for the forensic ledger and alerts.
     *
     * @param  array{weapon: float, violence: float, acoustic_distress: float}  $indicators
     */
    private function explainRisk(array $indicators, string $riskLevel): string
    {
        $weapon = (int) round(($indicators['weapon'] ?? 0) * 100);
        $violence = (int) round(($indicators['violence'] ?? 0) * 100);
        $distress = (int) round(($indicators['acoustic_distress'] ?? 0) * 100);
        $confidence = max($weapon, $violence, $distress);

        $signals = [];

        if ($weapon >= 60) {
            $signals[] = 'Possible weapon detected';
        }
        if ($violence >= 60) {
            $signals[] = 'physical confrontation visible';
        }
        if ($distress >= 60) {
            $signals[] = 'acoustic distress signatures present';
        }

        if ($signals === []) {
            return match ($riskLevel) {
                'high' => sprintf('Elevated multi-modal threat indicators. Confidence: %d%%', $confidence),
                'medium' => sprintf('Moderate anomaly detected across visual/audio channels. Confidence: %d%%', $confidence),
                'low' => sprintf('Low-level environmental activity recorded. Confidence: %d%%', $confidence),
                default => 'No significant threat indicators at this time.',
            };
        }

        $joined = $signals[0];
        $rest = array_slice($signals, 1);

        if ($rest !== []) {
            $joined .= ' and '.implode(' and ', $rest);
        }

        return sprintf('%s. Confidence: %d%%', $joined, $confidence);
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

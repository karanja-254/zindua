<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use App\Services\ElevenLabsDispatchService;
use App\Services\TelegramBroadcasterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\Concerns\UsesThreatAnalysisQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchVoiceBriefingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesThreatAnalysisQueue;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array{weapon: float, violence: float, acoustic_distress: float}  $aiIndicators
     */
    public function __construct(
        public readonly EvidenceSession $session,
        public readonly EvidenceChunk $chunk,
        public readonly array $aiIndicators,
    ) {
        $this->onThreatQueue();
    }

    /**
     * Exponential backoff (seconds) between attempts.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(
        ElevenLabsDispatchService $elevenLabs,
        TelegramBroadcasterService $telegram,
    ): void {
        $audioPath = $elevenLabs->generateVoiceBriefing($this->session, $this->chunk, $this->aiIndicators);

        if ($audioPath === null) {
            return;
        }

        $telegram->sendVoice(
            $this->session,
            $audioPath,
            sprintf('🔊 Emergency voice briefing — session %s', substr((string) $this->session->id, 0, 8)),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use App\Services\SmsDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\Concerns\UsesThreatAnalysisQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchSmsAlertJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use UsesThreatAnalysisQueue;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly EvidenceSession $session,
        public readonly EvidenceChunk $chunk,
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

    public function handle(SmsDispatchService $sms): void
    {
        $sms->dispatchEmergencyAlert($this->session, $this->chunk);
    }
}

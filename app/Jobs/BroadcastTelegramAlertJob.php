<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EvidenceChunk;
use App\Models\EvidenceSession;
use App\Services\TelegramBroadcasterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BroadcastTelegramAlertJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @param  array{weapon: float, violence: float, acoustic_distress: float}  $aiIndicators
     */
    public function __construct(
        public readonly EvidenceSession $session,
        public readonly EvidenceChunk $chunk,
        public readonly array $aiIndicators,
    ) {
        $this->onConnection('redis')->onQueue('threat-analysis');
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

    public function handle(TelegramBroadcasterService $telegram): void
    {
        try {
            $telegram->broadcastThreatAlert($this->session, $this->chunk, $this->aiIndicators);
        } catch (\Throwable $exception) {
            Log::error('BroadcastTelegramAlertJob swallowed a Telegram API error.', [
                'session_id' => $this->session->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

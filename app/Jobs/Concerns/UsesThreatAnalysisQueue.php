<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

trait UsesThreatAnalysisQueue
{
    protected function onThreatQueue(): void
    {
        $connection = (string) config('queue.default', 'sync');

        $this->onConnection($connection)->onQueue('threat-analysis');
    }
}

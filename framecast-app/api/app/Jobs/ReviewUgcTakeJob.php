<?php

namespace App\Jobs;

use App\Services\Ugc\UgcLearn;
use App\Services\Usage\UsageContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One UGC take reviewed after it finished or failed (UgcLearn, LEARN). Never retried: a missed review is only a missed lesson. */
class ReviewUgcTakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 300;

    public $tries = 1;

    public function __construct(public readonly array $take)
    {
    }

    public function handle(UgcLearn $learn): void
    {
        if (! config('services.ugc.learn', true)) return;
        UsageContext::set((int) ($this->take['workspace_id'] ?? 0) ?: null, (int) $this->take['project_id']);
        $learn->review($this->take);
    }
}

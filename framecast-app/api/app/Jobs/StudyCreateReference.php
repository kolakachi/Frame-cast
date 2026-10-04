<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\Create\References\ReferenceStudy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Studies a reference video in the background as soon as it is attached, so the
 * planner reads a finished study. Planning studies it itself if this has not
 * finished; the study is cached by source hash, so the work is never repeated.
 */
class StudyCreateReference implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public int $assetId, public ?string $mode = null) {}

    public function handle(ReferenceStudy $study): void
    {
        $asset = Asset::find($this->assetId);
        if ($asset && $asset->asset_type === 'video') $study->forAsset($asset, $this->mode);
    }
}

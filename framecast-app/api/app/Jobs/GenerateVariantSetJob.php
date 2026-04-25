<?php

namespace App\Jobs;

use App\Models\Variant;
use App\Models\VariantSet;
use App\Services\VariantGeneration\VariantGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Traits\TracksJobFailure;
use Illuminate\Foundation\Queue\Queueable;

class GenerateVariantSetJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    public function __construct(
        public readonly int $variantSetId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(VariantGenerationService $service): void
    {
        $variantSet = VariantSet::query()->with(['baseProject', 'variants'])->find($this->variantSetId);

        if (! $variantSet || ! $variantSet->baseProject) {
            return;
        }

        $variantSet->forceFill(['status' => 'generating'])->save();

        foreach ($variantSet->variants as $variant) {
            if (
                $variant->derived_project_id
                && in_array($variant->status, ['ready_for_review', 'rendered', 'queued'], true)
            ) {
                continue;
            }

            $variant->forceFill(['status' => 'pending'])->save();
            GenerateVariantJob::dispatch((int) $variant->getKey());
        }

        $service->refreshVariantSetStatus((int) $variantSet->getKey());
    }

    public function failed(?\Throwable $exception): void
    {
        if ($exception !== null) {
            $this->recordFailureTrace($exception, 'variant_set', $this->variantSetId);
        }

        app(VariantGenerationService::class)->refreshVariantSetStatus($this->variantSetId);
    }
}

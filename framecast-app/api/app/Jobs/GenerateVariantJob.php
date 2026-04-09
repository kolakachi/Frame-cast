<?php

namespace App\Jobs;

use App\Models\Variant;
use App\Services\Generation\TTS\TTSAdapter;
use App\Services\Generation\Visual\VisualProviderAdapter;
use App\Services\VariantGeneration\VariantGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateVariantJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $variantId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(
        VariantGenerationService $service,
        TTSAdapter $tts,
        VisualProviderAdapter $visualProvider,
    ): void {
        $variant = Variant::query()->with(['variantSet.baseProject'])->find($this->variantId);

        if (! $variant || ! $variant->variantSet || ! $variant->variantSet->baseProject) {
            return;
        }

        if (
            $variant->derived_project_id
            && in_array($variant->status, ['ready_for_review', 'rendered', 'queued'], true)
        ) {
            $service->refreshVariantSetStatus((int) $variant->variant_set_id);
            return;
        }

        $service->generateVariant($variant, $variant->variantSet->baseProject, $tts, $visualProvider);
        $service->refreshVariantSetStatus((int) $variant->variant_set_id);
    }

    public function failed(?\Throwable $exception): void
    {
        $variant = Variant::query()->find($this->variantId);

        if (! $variant) {
            return;
        }

        $variant->forceFill(['status' => 'failed'])->save();

        app(VariantGenerationService::class)->refreshVariantSetStatus((int) $variant->variant_set_id);
    }
}

<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Scene;
use App\Services\CreditService;
use App\Services\Generation\Image\ImageAdapterFactory;
use App\Services\Media\StorageService;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The AI video tab's storyboard (owner, 2026-10-30): three stills of the shot (start, middle, end) to approve before
 * paying for motion. Frame 1 is drawn from the reference image (or the description alone when there is none); frames 2
 * and 3 are each drawn from the frame before, so people, place and light carry through. The user picks the frame the
 * video starts from.
 *
 * Billing follows EditSceneImageJob: one deduction for all three frames up front, the whole amount back if any frame
 * fails, so a storyboard is either delivered or free. State lives on the scene under image_generation_settings_json
 * ['storyboard']; a newer request's token makes an older run stand down.
 */
class DrawStoryboardJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    public const FRAMES = 3;

    public const DEFAULT_MODEL = 'nano-banana';

    /** Models that draw from a picture (ImageAdapterFactory::referenceAdapter). */
    public const MODELS = ['nano-banana', 'nano-banana-pro', 'gpt-image-2'];

    private const MOMENTS = [
        'the opening moment of the shot',
        'the middle of the shot, a few seconds after the reference image',
        'the final moment of the shot, at the end of the action',
    ];

    public int $timeout = 600;

    public int $tries = 1; // a failure refunds; a silent retry would double-charge

    public function __construct(
        public readonly int $sceneId,
        public readonly int $projectId,
        public readonly string $prompt,
        public readonly string $modelKey,
        public readonly ?int $referenceAssetId,
        public readonly string $token,
    ) {
        $this->onQueue('generation');
    }

    /** What three frames cost: the first from a picture or from words, the next two always from the frame before. */
    public static function cost(string $modelKey, bool $hasReference): int
    {
        $factory = app(ImageAdapterFactory::class);
        $fromPicture = $factory->referenceGenerationCost($modelKey);
        $first = $hasReference ? $fromPicture : $factory->costFor($modelKey);

        return $first + ($fromPicture * (self::FRAMES - 1));
    }

    public function handle(): void
    {
        $scene = Scene::query()->with('project')->find($this->sceneId);
        if (! $scene || ! $scene->project || ! $this->current($scene)) {
            return;
        }

        $workspaceId = (int) $scene->project->workspace_id;
        $reference = $this->referenceAssetId ? Asset::query()->whereKey($this->referenceAssetId)->where('workspace_id', $workspaceId)->first() : null;
        $cost = self::cost($this->modelKey, $reference !== null);
        $factory = app(ImageAdapterFactory::class);
        $credits = app(CreditService::class);

        $charged = $credits->deduct($workspaceId, $cost, 'ai_image:storyboard', [
            'project_id'        => $this->projectId,
            'scene_id'          => $this->sceneId,
            'user_id'           => $scene->project->created_by_user_id,
            'upstream_cost_usd' => CreditService::cogsUsd($factory->referenceCogsKey($this->modelKey)) * self::FRAMES,
            'metadata'          => ['model_key' => $this->modelKey, 'frames' => self::FRAMES, 'reference_asset_id' => $reference?->getKey()],
        ]);
        if (! $charged) {
            $this->finish($scene, ['status' => 'failed', 'error' => "You need {$cost} credits to draw the storyboard."]);

            return;
        }

        $style = $scene->visual_style ?: 'cinematic';
        $aspect = $scene->project->aspect_ratio ?: '9:16';
        $frames = [];
        $previous = $reference;

        try {
            foreach (self::MOMENTS as $i => $moment) {
                $prompt = $this->framePrompt($i, $moment, $previous !== null);
                $result = $previous !== null
                    ? $factory->referenceAdapter($this->modelKey)->generate($prompt, $style, $aspect, ['reference_image_urls' => [$this->signedUrl($previous)]])
                    : $factory->resolve($this->modelKey)->generate($prompt, $style, $aspect);

                $path = $this->store($result['image_url'] ?? null, $workspaceId, $result['image_b64'] ?? null);
                $previous = Asset::query()->create([
                    'workspace_id'     => $workspaceId,
                    'channel_id'       => $scene->project->channel_id,
                    'asset_type'       => 'image',
                    'title'            => sprintf('Storyboard %d/%d · Scene %d', $i + 1, self::FRAMES, $scene->scene_order),
                    'description'      => $this->prompt,
                    'storage_url'      => $path,
                    'thumbnail_url'    => $path,
                    'duration_seconds' => null,
                    'dimensions_json'  => ['width' => $result['width'] ?? null, 'height' => $result['height'] ?? null],
                    'mime_type'        => 'image/png',
                    'tags'             => ['ai_generated', 'storyboard', $result['provider_key'] ?? $this->modelKey],
                ]);
                $frames[] = ['asset_id' => $previous->getKey()];

                // Show each frame as it lands; stand down if a newer storyboard was asked for meanwhile.
                $scene->refresh();
                if (! $this->current($scene)) {
                    return;
                }
                $this->patch($scene, ['frames' => $frames]);
            }
        } catch (\Throwable $e) {
            // A storyboard that is not delivered whole costs nothing. tries = 1, so this refunds once.
            $credits->refund($workspaceId, $cost, 'ai_image:storyboard');
            $this->finish($scene->fresh() ?? $scene, ['status' => 'failed', 'frames' => [], 'error' => 'The storyboard could not be drawn. Your credits have not been charged.']);

            throw $e;
        }

        $this->finish($scene, ['status' => 'ready', 'frames' => $frames, 'start' => 0, 'error' => null, 'completed_at' => now()->toIso8601String()]);
    }

    public function failed(\Throwable $exception): void
    {
        $scene = Scene::query()->with('project')->find($this->sceneId);
        $this->recordFailureTrace($exception, 'scene', $this->sceneId, $scene->project->workspace_id ?? null, $this->projectId);
    }

    private function framePrompt(int $index, string $moment, bool $fromPicture): string
    {
        $frame = sprintf('Storyboard frame %d of %d: %s.', $index + 1, self::FRAMES, $moment);
        $keep = $index === 0
            ? ($fromPicture ? ' Keep the people, product and place from the reference image.' : '')
            : ' Same people, clothes, product, place, light and camera style as the reference image; only the action and the camera move on.';

        return trim($this->prompt)."\n\n".$frame.$keep.' One photographic still, no text, no captions, no borders, no panels.';
    }

    private function current(Scene $scene): bool
    {
        return data_get($scene->image_generation_settings_json, 'storyboard.token') === $this->token;
    }

    private function patch(Scene $scene, array $changes): void
    {
        $settings = $scene->image_generation_settings_json ?? [];
        $settings['storyboard'] = array_merge((array) ($settings['storyboard'] ?? []), $changes);
        $scene->forceFill(['image_generation_settings_json' => $settings])->save();
    }

    private function finish(Scene $scene, array $changes): void
    {
        if (! $this->current($scene)) {
            return;
        }
        $this->patch($scene, $changes);
        GenerationProgressed::dispatch($this->projectId, 'storyboard', $changes['status'] ?? 'completed', $changes['error'] ?? null, ['scene_id' => $this->sceneId]);
    }

    private function signedUrl(Asset $asset): string
    {
        $storage = app(StorageService::class);
        if ($storage->extractPath((string) $asset->storage_url) === null) {
            return (string) $asset->storage_url; // already an external URL
        }

        return URL::temporarySignedRoute('media.assets.content', now()->addMinutes(30), ['assetId' => $asset->getKey()]);
    }

    private function store(?string $url, int $workspaceId, ?string $b64): string
    {
        $contents = $b64 !== null ? (base64_decode($b64, true) ?: '') : Http::timeout(30)->get((string) $url)->body();
        if ($contents === '') {
            throw new RuntimeException('A storyboard frame came back empty.');
        }

        return app(StorageService::class)->put(sprintf('workspaces/%s/assets/ai-images/%s.png', $workspaceId, Str::uuid()), $contents);
    }
}

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
 * Change one thing about a scene's existing image, keeping the rest.
 *
 * Regenerating throws the picture away and rolls again, so the only way to fix
 * a detail was to keep paying for new pictures and hope. A customer spent
 * 96 credits on six rerolls of one ad before getting a frame he could use.
 *
 * This feeds the scene's current image back to the reference adapter as
 * image_input — the same path a character likeness already takes — with the
 * user's instruction as the prompt. At 10cr it also undercuts the 16cr reroll
 * it replaces, so the saving is both per call and in needing fewer of them.
 *
 * Metered as `ai_image:edit` rather than reusing `ai_image:manual`, so it is
 * answerable later whether edits actually displaced rerolls or merely added
 * to them. That question cannot be reconstructed from a shared operation.
 */
class EditSceneImageJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    /**
     * Edits pin to nano-banana rather than inheriting the general reference
     * default (nano-banana-pro, 35cr). An edit has to be cheaper than the
     * reroll it replaces — 16cr — or it solves nothing. nano-banana is 10cr
     * and is the adapter that best preserves a subject under change, which
     * is the whole job here.
     */
    public const EDIT_MODEL = 'nano-banana';

    public int $timeout = 300;
    public int $tries = 1; // a failure refunds; a silent retry would double-charge

    public function __construct(
        public readonly int $sceneId,
        public readonly int $projectId,
        public readonly string $instruction,
        public readonly ?string $modelKey = null,
    ) {
        $this->onQueue('generation');
    }

    public function handle(): void
    {
        $scene = Scene::query()->with('project')->find($this->sceneId);

        if (! $scene || ! $scene->project) {
            return;
        }

        $source = $scene->visual_asset_id ? Asset::query()->find((int) $scene->visual_asset_id) : null;

        if (! $source) {
            // Nothing to edit. Say so rather than quietly generating from
            // scratch, which would bill for a picture they did not ask for.
            $this->fail_scene($scene, 'This scene has no image to edit yet. Generate one first.');

            return;
        }

        $factory  = app(ImageAdapterFactory::class);
        $model    = $this->modelKey ?: self::EDIT_MODEL;
        $reserved = $factory->referenceGenerationCost($model);
        $credits  = app(CreditService::class);

        $charged = $credits->deduct(
            (int) $scene->project->workspace_id,
            $reserved,
            'ai_image:edit',
            [
                'project_id'        => $this->projectId,
                'scene_id'          => $this->sceneId,
                'user_id'           => $scene->project->created_by_user_id,
                'upstream_cost_usd' => CreditService::cogsUsd($factory->referenceCogsKey($model)),
                'metadata'          => ['model_key' => $model, 'source_asset_id' => $source->getKey()],
            ],
        );

        if (! $charged) {
            $this->fail_scene($scene, "You need {$reserved} credits to edit this image.");

            return;
        }

        $this->mark($scene, ['in_progress' => true, 'last_error' => null]);

        try {
            $result = $factory->referenceAdapter($model)->generate(
                $this->instruction,
                $scene->visual_style ?: 'cinematic',
                $scene->project->aspect_ratio ?: '9:16',
                ['reference_image_urls' => [$this->signedUrl($source)]],
            );

            $storagePath = $this->store($result['image_url'] ?? null, $scene, $result['image_b64'] ?? null);

            $asset = Asset::query()->create([
                'workspace_id'     => $scene->project->workspace_id,
                'channel_id'       => $scene->project->channel_id,
                'asset_type'       => 'image',
                'title'            => "Edited — Scene {$scene->scene_order}",
                'description'      => $this->instruction,
                'storage_url'      => $storagePath,
                'thumbnail_url'    => $storagePath,
                'duration_seconds' => null,
                'dimensions_json'  => ['width' => $result['width'], 'height' => $result['height']],
                'mime_type'        => 'image/png',
                'tags'             => ['ai_generated', 'edited', $result['provider_key']],
            ]);

            // The previous image is left in place as an asset. An edit the user
            // dislikes is then a matter of pointing the scene back at it, not
            // of paying to generate the old one again.
            $scene->forceFill([
                'visual_asset_id'                => $asset->getKey(),
                'image_generation_settings_json' => array_merge($scene->image_generation_settings_json ?? [], [
                    'in_progress'      => false,
                    'needs_visual'     => false,
                    'last_error'       => null,
                    'edited_from_asset_id' => $source->getKey(),
                    'last_edit_instruction' => $this->instruction,
                ]),
            ])->save();

            GenerationProgressed::dispatch($this->projectId, 'ai_image', 'completed', null, ['scene_id' => $this->sceneId]);
        } catch (\Throwable $e) {
            // A failed edit must cost nothing. tries = 1, so this refunds once.
            $credits->refund((int) $scene->project->workspace_id, $reserved, 'ai_image:edit');
            $this->fail_scene($scene, 'That edit could not be applied. Your credits have not been charged.');

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $scene = Scene::query()->with('project')->find($this->sceneId);
        $this->recordFailureTrace(
            $exception,
            'scene',
            $this->sceneId,
            $scene->project->workspace_id ?? null,
            $this->projectId,
        );
    }

    private function fail_scene(Scene $scene, string $message): void
    {
        $this->mark($scene, ['in_progress' => false, 'last_error' => $message]);
        GenerationProgressed::dispatch($this->projectId, 'ai_image', 'failed', $message, ['scene_id' => $this->sceneId]);
    }

    private function mark(Scene $scene, array $patch): void
    {
        $scene->forceFill([
            'image_generation_settings_json' => array_merge($scene->image_generation_settings_json ?? [], $patch),
        ])->save();
    }

    private function signedUrl(Asset $asset): string
    {
        $storage = app(StorageService::class);

        if ($storage->extractPath((string) $asset->storage_url) === null) {
            return (string) $asset->storage_url; // already an external URL
        }

        return URL::temporarySignedRoute(
            'media.assets.content',
            now()->addMinutes(30),
            ['assetId' => $asset->getKey()],
        );
    }

    private function store(?string $url, Scene $scene, ?string $b64): string
    {
        $contents = $b64 !== null
            ? (base64_decode($b64, true) ?: '')
            : Http::timeout(30)->get((string) $url)->body();

        if ($contents === '') {
            throw new RuntimeException('The edited image came back empty.');
        }

        return app(StorageService::class)->put(
            sprintf('workspaces/%s/assets/ai-images/%s.png', $scene->project->workspace_id, Str::uuid()),
            $contents,
        );
    }
}

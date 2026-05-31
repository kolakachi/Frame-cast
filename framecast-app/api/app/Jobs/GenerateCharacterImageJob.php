<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\Character;
use App\Models\CharacterImageGeneration;
use App\Services\CreditService;
use App\Services\Generation\Image\CharacterImageAdapter;
use App\Services\Generation\Image\DalleImageAdapter;
use App\Services\Media\StorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Background worker for "generate test image" on a Character. Lives on the
 * generation queue so it can run for 30-120s without holding the HTTP
 * connection open (Cloudflare 502'd on the synchronous version).
 *
 * Two paths:
 *  - Character has a reference asset → CharacterImageAdapter (gpt-image-2 /edits)
 *  - No reference → DalleImageAdapter (gpt-image-1 text-only)
 *
 * On success: stores result as an Asset, optionally promotes it to the
 * character's reference, charges credits, marks the generation row succeeded.
 * On failure: stores the error message on the row; credits are NOT charged.
 */
class GenerateCharacterImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300; // 5 min — comfortably above gpt-image-2's worst case

    public function __construct(public readonly int $generationId)
    {
    }

    public function handle(
        CreditService $credits,
        StorageService $storage,
        CharacterImageAdapter $characterAdapter,
        DalleImageAdapter $dalleAdapter,
    ): void {
        $gen = CharacterImageGeneration::query()->find($this->generationId);
        if (! $gen) {
            return; // row deleted between dispatch and run
        }
        if ($gen->status !== 'queued') {
            return; // already picked up or finished
        }

        $character = Character::query()->with('referenceAsset')->find($gen->character_id);
        if (! $character) {
            $this->markFailed($gen, 'Character no longer exists.');
            return;
        }

        $hasReference = (bool) $character->reference_asset_id;
        $cost = $hasReference ? CreditService::AI_CHARACTER : CreditService::AI_MEDIUM;

        // Re-check balance before charging — user may have spent credits between
        // the request landing and the worker picking it up.
        if ($credits->balance((int) $gen->workspace_id) < $cost) {
            $this->markFailed($gen, "Workspace no longer has the {$cost} credits needed for this generation.");
            return;
        }

        $gen->update([
            'status'         => 'processing',
            'started_at'     => now(),
            'used_reference' => $hasReference,
        ]);

        $options = [
            'usage_context' => [
                'workspace_id' => $gen->workspace_id,
                'user_id'      => $gen->user_id,
                'character_id' => $character->getKey(),
                'style'        => $gen->style,
            ],
            'quality' => (string) ($gen->quality ?: ($hasReference ? 'high' : 'medium')),
        ];

        try {
            if ($hasReference) {
                $referenceUrl = $this->signedAssetUrl($character->referenceAsset);
                if (! $referenceUrl) {
                    throw new \RuntimeException('Character reference image is missing or unreadable.');
                }
                $options['reference_image_url'] = $referenceUrl;
                $result = $characterAdapter->generate(
                    (string) $gen->prompt,
                    (string) ($gen->style ?? 'photorealistic'),
                    (string) ($gen->aspect_ratio ?? '9:16'),
                    $options
                );
            } else {
                $promptWithCharacter = trim($character->description
                    ? "Portrait of {$character->name}: {$character->description}. {$gen->prompt}"
                    : "Portrait of {$character->name}. {$gen->prompt}");
                $result = $dalleAdapter->generate(
                    $promptWithCharacter,
                    (string) ($gen->style ?? 'photorealistic'),
                    (string) ($gen->aspect_ratio ?? '9:16'),
                    $options
                );
            }
        } catch (Throwable $e) {
            Log::error('GenerateCharacterImageJob adapter failed', [
                'generation_id' => $gen->getKey(),
                'character_id'  => $character->getKey(),
                'error'         => $e->getMessage(),
            ]);
            $this->markFailed($gen, $e->getMessage());
            return;
        }

        try {
            $asset = $this->storeAsAsset($gen, $character, $result, $storage);
        } catch (Throwable $e) {
            Log::error('GenerateCharacterImageJob storage failed', [
                'generation_id' => $gen->getKey(),
                'character_id'  => $character->getKey(),
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);
            $this->markFailed($gen, 'Storage failed: '.$e->getMessage());
            return;
        }

        if (! $asset) {
            $this->markFailed($gen, 'Generated image bytes were empty.');
            return;
        }

        // Charge on success.
        $credits->deduct((int) $gen->workspace_id, $cost, 'character_preview');

        // Every generated image is appended to this character's reference list,
        // whether or not it was promoted to primary. That way, when the user
        // leaves the page and comes back, the image isn't orphaned — it's
        // visible in the edit modal's reference grid and can be promoted later.
        // The `set_as_reference` flag only controls whether it ALSO becomes the
        // primary (reference_asset_id) that the adapter routes through.
        try {
            $existingIds = is_array($character->reference_asset_ids) ? $character->reference_asset_ids : [];
            // Defensive: strip the new id if somehow already present, then choose
            // prepend (primary) vs append (just join the list).
            $existingIds = array_values(array_filter($existingIds, fn ($id) => (int) $id !== $asset->getKey()));

            if ($gen->set_as_reference) {
                $character->update([
                    'reference_asset_id'  => $asset->getKey(),
                    'reference_asset_ids' => array_values(array_merge([$asset->getKey()], $existingIds)),
                ]);
            } else {
                $character->update([
                    'reference_asset_ids' => array_values(array_merge($existingIds, [$asset->getKey()])),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('GenerateCharacterImageJob reference-list append failed', [
                'generation_id' => $gen->getKey(),
                'error'         => $e->getMessage(),
            ]);
            // Image is still stored; the link just didn't attach. Non-fatal.
        }

        $gen->update([
            'status'          => 'succeeded',
            'result_asset_id' => $asset->getKey(),
            'credits_charged' => $cost,
            'completed_at'    => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $gen = CharacterImageGeneration::query()->find($this->generationId);
        if ($gen && $gen->status !== 'succeeded') {
            $this->markFailed($gen, $exception->getMessage());
        }
    }

    private function markFailed(CharacterImageGeneration $gen, string $message): void
    {
        $gen->update([
            'status'        => 'failed',
            'error_message' => $message,
            'completed_at'  => now(),
        ]);
    }

    private function storeAsAsset(
        CharacterImageGeneration $gen,
        Character $character,
        array $result,
        StorageService $storage,
    ): ?Asset {
        $imageBytes = null;
        if (! empty($result['image_b64'])) {
            $imageBytes = base64_decode($result['image_b64'], true);
        } elseif (! empty($result['image_url'])) {
            $fetched = Http::timeout(60)->get($result['image_url']);
            if (! $fetched->successful()) {
                throw new \RuntimeException("Could not download generated image (HTTP {$fetched->status()}).");
            }
            $imageBytes = $fetched->body();
        }
        if ($imageBytes === null || $imageBytes === false || $imageBytes === '') {
            return null;
        }

        $filename = 'characters/'.$character->getKey().'/preview-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.png';
        $stored   = $storage->put($filename, $imageBytes, ['ContentType' => 'image/png']);

        return Asset::query()->create([
            'workspace_id'         => $gen->workspace_id,
            'channel_id'           => null,
            'asset_type'           => 'image',
            'title'                => "Character preview — {$character->name}",
            'description'          => 'Generated character preview',
            'storage_url'          => $stored,
            'thumbnail_url'        => $stored,
            'mime_type'            => 'image/png',
            'dimensions_json'      => [
                'width'  => (int) ($result['width']  ?? 1024),
                'height' => (int) ($result['height'] ?? 1536),
            ],
            'tags'                 => ['character_preview', $character->name, (string) ($gen->style ?? '')],
            'transcription_status' => 'not_requested',
            'restriction_scope'    => 'workspace',
            'usage_count'          => 0,
            'status'               => 'active',
            'created_by_user_id'   => $gen->user_id,
        ]);
    }

    private function signedAssetUrl(?Asset $asset): ?string
    {
        if (! $asset || ! $asset->storage_url) {
            return null;
        }
        $isStoredPath = app(StorageService::class)->extractPath((string) $asset->storage_url) !== null;
        if (! $isStoredPath) {
            return (string) $asset->storage_url;
        }
        return URL::temporarySignedRoute(
            'media.assets.content',
            now()->addMinutes(30),
            ['assetId' => $asset->getKey()],
        );
    }
}

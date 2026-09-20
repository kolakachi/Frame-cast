<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Services\CreditService;
use App\Services\Generation\Video\ReplicateVeoAdapter;
use App\Services\Media\StorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * One full UGC video from compiled screenplay chunks: each chunk generates
 * on Veo with native speech, each next chunk starts from the previous one's
 * tail frame so the presenter carries through, and the segments concat into
 * a single take. No scenes, no TTS, no lip-sync — the ad is born whole.
 *
 * Charge-on-success: a failed segment charges nothing and leaves the error
 * on the scene for a free retry of the whole take.
 */
class GenerateOneShotUgcJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3500;

    public $tries = 1;

    /** @param array<int, array{prompt: string, seconds: int}> $chunks */
    public function __construct(
        public readonly int $projectId,
        public readonly int $sceneId,
        public readonly array $chunks,
        public readonly int $credits,
        public readonly string $engine = 'veo',
        /** @var array<int, string> data URIs for Seedance reference_images */
        public readonly array $referenceImages = [],
        /** Chunk 0's start frame — how a cast character anchors identity on Veo. */
        public readonly ?string $initialStartFrame = null,
        /** Per-character seed for Seedance variant takes. */
        public readonly ?int $seed = null,
        /** 480p (draft) or 720p — Seedance only; Veo stays 720p. */
        public readonly string $resolution = '720p',
    ) {
        $this->onQueue('generation');
    }

    public function handle(ReplicateVeoAdapter $veo, StorageService $storage, CreditService $credits): void
    {
        $project = Project::query()->find($this->projectId);
        $scene = Scene::query()->find($this->sceneId);
        if (! $project || ! $scene || $this->chunks === []) {
            return;
        }

        $temps = [];
        try {
            $segmentPaths = [];
            $startFrame = $this->initialStartFrame;
            // veo-3.1-fast has no reference-image inputs, so packaging can
            // only reach an exact take through the start frame itself:
            // composite the character holding the product (same /edits
            // pipeline the multi-scene lane uses). Runs on the worker, its
            // small image cost is absorbed, and any failure falls back to
            // the plain character frame.
            if ($startFrame !== null && $this->referenceImages !== [] && $this->engine === 'veo') {
                try {
                    $composite = app(\App\Services\Generation\Image\CharacterImageAdapter::class)->generate(
                        'Vertical 9:16 selfie-framing still of the person from the first reference image naturally holding the product shown in the other reference images at chest height, label facing the camera, casual lived-in setting, soft daylight, authentic phone-camera feel',
                        'realistic', '9:16',
                        ['reference_image_urls' => array_slice(array_merge([$startFrame], $this->referenceImages), 0, 4), 'quality' => 'medium'],
                    );
                    $bytes = ! empty($composite['image_b64']) ? base64_decode($composite['image_b64'], true)
                        : (! empty($composite['image_url']) ? @file_get_contents($composite['image_url']) : null);
                    if (is_string($bytes) && $bytes !== '') {
                        $startFrame = 'data:image/png;base64,'.base64_encode($bytes);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('one-shot: product composite failed, plain character frame used', [
                        'project_id' => $this->projectId, 'error' => mb_substr($e->getMessage(), 0, 200),
                    ]);
                }
            }
            foreach ($this->chunks as $i => $chunk) {
                // Provider containers occasionally die with a bare transport
                // error (httpx.ReadError, empty error string) — transient, and
                // worth one automatic retry before failing a multi-segment
                // take over it.
                $url = null;
                $lastTransient = null;
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    // veo_hq keeps the character face as a reference on
                    // EVERY chunk so identity holds across the chain (the
                    // scene stays creative — no start frame on chunk 0). Other
                    // engines only need refs on chunk 0.
                    $chunkRefs = ($this->engine === 'veo_hq' || $i === 0) ? $this->referenceImages : [];
                    $predictionId = $veo->start((string) $chunk['prompt'], (int) $chunk['seconds'], $startFrame, $this->engine, $chunkRefs, $this->seed, $this->resolution);
                    $scene->forceFill(['image_generation_settings_json' => array_merge(
                        $scene->image_generation_settings_json ?? [],
                        ['oneshot_segment' => $i + 1, 'oneshot_total' => count($this->chunks), 'oneshot_prediction_id' => $predictionId],
                    )])->save();

                    try {
                        $url = $veo->pollUntilDone($predictionId);
                        break;
                    } catch (\RuntimeException $e) {
                        $transient = trim(str_replace('Veo generation failed:', '', $e->getMessage())) === ''
                            || str_contains($e->getMessage(), 'ReadError');
                        if (! $transient || $attempt === 1) {
                            throw $e;
                        }
                        $lastTransient = $e;
                    }
                }
                if ($url === null || $url === '') {
                    throw $lastTransient ?? new \RuntimeException(sprintf('Segment %d of %d did not finish in time. Retry the take — nothing was charged.', $i + 1, count($this->chunks)));
                }

                $path = tempnam(sys_get_temp_dir(), 'oneshot-').'.mp4';
                $temps[] = $path;
                file_put_contents($path, file_get_contents($url));
                if (! filesize($path)) {
                    throw new \RuntimeException(sprintf('Segment %d came back empty.', $i + 1));
                }
                $segmentPaths[] = $path;

                // The tail frame anchors the next segment's identity.
                if ($i < count($this->chunks) - 1) {
                    $framePath = tempnam(sys_get_temp_dir(), 'oneshot-frame-').'.png';
                    $temps[] = $framePath;
                    $result = Process::timeout(60)->run(['ffmpeg', '-y', '-sseof', '-0.15', '-i', $path, '-frames:v', '1', $framePath]);
                    if (! $result->successful() || ! filesize($framePath)) {
                        throw new \RuntimeException(sprintf('Could not read the handoff frame after segment %d.', $i + 1));
                    }
                    $startFrame = 'data:image/png;base64,'.base64_encode(file_get_contents($framePath));
                }
            }

            $finalPath = $this->concat($segmentPaths, $temps);

            $storagePath = sprintf('workspaces/%d/assets/ugc-oneshot/%s.mp4', $project->workspace_id, Str::uuid());
            $storageUrl = $storage->put($storagePath, file_get_contents($finalPath), ['ContentType' => 'video/mp4']);

            $probe = Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $finalPath]);
            $duration = (float) trim($probe->output()) ?: null;

            $asset = Asset::query()->create([
                'workspace_id' => $project->workspace_id,
                'asset_type' => 'video',
                'title' => mb_substr('UGC — '.$project->title, 0, 255),
                'storage_url' => $storageUrl,
                'mime_type' => 'video/mp4',
                'file_size_bytes' => filesize($finalPath),
                'duration_seconds' => $duration,
                'tags' => ['ugc_oneshot'],
                'status' => 'active',
                'created_by_user_id' => $project->created_by_user_id,
            ]);

            $scene->forceFill([
                'visual_asset_id' => $asset->id,
                'duration_seconds' => $duration ?: $scene->duration_seconds,
                'status' => 'ready',
                'image_generation_settings_json' => array_merge(
                    $scene->image_generation_settings_json ?? [],
                    ['in_progress' => false, 'last_error' => null],
                ),
            ])->save();

            $project->forceFill(['status' => 'ready_for_review'])->save();

            // Money moves only now, with the finished video stored.
            $credits->deduct($project->workspace_id, $this->credits, 'ugc_oneshot', [
                'project_id' => $this->projectId, 'scene_id' => $this->sceneId,
                'segments' => count($this->chunks),
            ]);
        } finally {
            foreach ($temps as $t) {
                @unlink($t);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Scene::query()->whereKey($this->sceneId)->get()->each(function (Scene $scene) use ($e): void {
            $scene->forceFill(['image_generation_settings_json' => array_merge(
                $scene->image_generation_settings_json ?? [],
                ['in_progress' => false, 'last_error' => mb_substr($e->getMessage(), 0, 300)],
            )])->save();
        });
    }

    /** Segments share codec/resolution by construction; concat without re-encode, re-encode as fallback. */
    private function concat(array $paths, array &$temps): string
    {
        if (count($paths) === 1) {
            return $paths[0];
        }
        $list = tempnam(sys_get_temp_dir(), 'oneshot-list-').'.txt';
        $temps[] = $list;
        file_put_contents($list, implode("\n", array_map(fn ($p) => "file '".$p."'", $paths)));

        $out = tempnam(sys_get_temp_dir(), 'oneshot-final-').'.mp4';
        $temps[] = $out;
        $copy = Process::timeout(300)->run(['ffmpeg', '-y', '-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', $out]);
        if ($copy->successful() && filesize($out)) {
            return $out;
        }
        $encode = Process::timeout(900)->run([
            'ffmpeg', '-y', '-f', 'concat', '-safe', '0', '-i', $list,
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '19', '-c:a', 'aac', $out,
        ]);
        if (! $encode->successful() || ! filesize($out)) {
            throw new \RuntimeException('The segments could not be joined into one video.');
        }

        return $out;
    }
}

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * One full UGC video from compiled screenplay chunks: each chunk generates
 * on Veo with native speech, each next chunk starts from the previous one's
 * tail frame so the presenter carries through, and the segments concat into
 * a single take. No scenes, no TTS, no lip-sync — the ad is born whole.
 *
 * Charge-on-success: a failed segment charges nothing and leaves the error
 * on the scene for a free retry.
 *
 * Resumable (owner, 2026-10-10): each finished segment, and the frame the next
 * one starts from, is saved as it lands, with the job's recipe. A retry
 * (resume()) skips the saved segments and starts at the one that failed, so a
 * failure on the last clip never pays for the first ones twice. Saved segments
 * are reused only while the recipe is unchanged, and are removed once the
 * video is delivered, when the take is deleted, or after PARTS_DAYS.
 */
class GenerateOneShotUgcJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Under the queue's retry_after (2400 s on prod), so a long run is never handed to a second worker while it is
    // still going. A run that hits it fails and resumes from its saved segments.
    public $timeout = 2300;

    public $tries = 1;

    public const PARTS_DAYS = 7;

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
        /** Storage URL of a real demo clip, spliced over a window in post
         *  (ffmpeg) so the ACTUAL recording shows — not a model recreation. */
        public readonly ?string $demoStorageUrl = null,
        /** Which run owns the take (oneshot_token on the scene). A job whose token is no longer the scene's — a
         *  resumed or redelivered copy — stops without saving, failing or charging. Null: dispatched before tokens. */
        public readonly ?string $runToken = null,
    ) {
        $this->onQueue('generation');
    }

    public function handle(ReplicateVeoAdapter $veo, StorageService $storage, CreditService $credits): void
    {
        $project = Project::query()->find($this->projectId);
        $scene = Scene::query()->find($this->sceneId);
        if (! $project || ! $scene || $this->chunks === [] || ! $this->owns($scene)) {
            return;
        }

        $temps = [];
        try {
            $segmentPaths = [];
            $startFrame = $this->initialStartFrame;
            $saved = $this->prepareParts($project, $scene, $storage);
            // Saved clips are reused only up to the first one that must be made again: a later saved clip started from
            // the old clip's last frame and would not join the new one.
            $reuse = [];
            foreach (array_keys($this->chunks) as $i) {
                $bytes = isset($saved[$i]) ? $storage->get($saved[$i]['url']) : null;
                if (! is_string($bytes) || $bytes === '') break;
                $reuse[$i] = $bytes;
            }
            // veo-3.1-fast has no reference-image inputs, so packaging can
            // only reach an exact take through the start frame itself:
            // composite the character holding the product (same /edits
            // pipeline the multi-scene lane uses). Runs on the worker, its
            // small image cost is absorbed, and any failure falls back to
            // the plain character frame.
            if (! isset($reuse[0]) && $startFrame !== null && $this->referenceImages !== [] && $this->engine === 'veo') {
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
                // A segment saved by an earlier attempt is used as it is; the next one starts from its last frame.
                if (isset($reuse[$i])) {
                    $path = tempnam(sys_get_temp_dir(), 'oneshot-').'.mp4';
                    $temps[] = $path;
                    file_put_contents($path, $reuse[$i]);
                } else {
                    if (! $this->owns($scene->refresh())) return; // superseded: the newer run makes the rest
                    $path = $this->generateSegment($veo, $scene, $i, $chunk, $startFrame, $temps);
                    $this->savePart($project, $scene, $storage, $i, $path);
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

            // Post-composite: splice the ACTUAL demo clip full-frame over a
            // mid-ad window while the presenter's voice keeps playing — the
            // real recording, crisp and unchanged, not a model recreation.
            if ($this->demoStorageUrl !== null) {
                $finalPath = $this->compositeDemo($finalPath, $this->demoStorageUrl, $storage, $temps);
            }

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

            // Only the run that owns the take delivers and charges. The ownership check, the delivery and the charge run
            // under the scene's row lock, the same lock resume() claims under, so one always sees the other.
            $delivered = DB::transaction(function () use ($scene, $project, $asset, $duration, $credits) {
                $row = DB::table('scenes')->where('id', $this->sceneId)->lockForUpdate()->value('image_generation_settings_json');
                $token = (json_decode((string) $row, true) ?: [])['oneshot_token'] ?? null;
                if ($this->runToken !== null && $token !== null && $token !== $this->runToken) return false;
                $scene->refresh();
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
                return true;
            });
            if (! $delivered) {
                return;
            }

            // Delivered: the saved segments have done their job.
            self::forgetParts($scene->refresh(), (int) $project->workspace_id);
        } finally {
            foreach ($temps as $t) {
                @unlink($t);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Scene::query()->whereKey($this->sceneId)->get()->filter(fn (Scene $scene) => $this->owns($scene))->each(function (Scene $scene) use ($e): void {
            $scene->forceFill(['image_generation_settings_json' => array_merge(
                $scene->image_generation_settings_json ?? [],
                ['in_progress' => false, 'last_error' => mb_substr($e->getMessage(), 0, 300)],
            )])->save();
        });

        $this->releasePassTake();
    }

    /**
     * Give a Test Pass holder their take back when the generation failed.
     *
     * Credits are charged on success only, so a failure costs none — but the
     * take was reserved up front, and without this a customer could lose both
     * attempts and never receive a video. The release is its own ledger row;
     * the reservation count nets the two.
     */
    private function releasePassTake(): void
    {
        app(\App\Services\UgcPassTakeService::class)->releaseProject($this->projectId);
    }

    /**
     * Splice the real demo clip over a centred mid-ad window: the ACTUAL
     * recording plays full-frame (letterboxed to the ad's frame) while the
     * presenter's voice continues underneath, then the ad cuts back. Nothing
     * generative — the clip is shown exactly as recorded.
     */
    private function compositeDemo(string $basePath, string $demoStorageUrl, StorageService $storage, array &$temps): string
    {
        $demoPath = tempnam(sys_get_temp_dir(), 'oneshot-demo-').'.mp4';
        $temps[] = $demoPath;
        $stream = $storage->readStream($demoStorageUrl);
        if (! is_resource($stream)) {
            throw new \RuntimeException('The demo clip could not be read for compositing.');
        }
        file_put_contents($demoPath, $stream);
        if (is_resource($stream)) {
            @fclose($stream);
        }
        if (! filesize($demoPath)) {
            throw new \RuntimeException('The demo clip came back empty.');
        }

        // Base geometry + both durations drive the placement.
        $dims = Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height', '-of', 'csv=s=x:p=0', $basePath]);
        [$w, $h] = array_pad(array_map('intval', explode('x', trim($dims->output()))), 2, 0);
        $baseLen = (float) trim(Process::timeout(30)->run(['ffprobe', '-v', 'error',
            '-show_entries', 'format=duration', '-of', 'csv=p=0', $basePath])->output());
        $demoLen = (float) trim(Process::timeout(30)->run(['ffprobe', '-v', 'error',
            '-show_entries', 'format=duration', '-of', 'csv=p=0', $demoPath])->output());
        if ($w < 2 || $h < 2 || $baseLen <= 0 || $demoLen <= 0) {
            throw new \RuntimeException('Could not measure the ad or demo clip for compositing.');
        }

        // Keep a presenter head and tail; centre the demo in what remains.
        $head = 3.0;
        $tail = 2.0;
        $avail = $baseLen - $head - $tail;
        if ($avail < 1.5) {
            // Too short to fit a cutaway — fail loudly (free retry) rather than
            // silently ship a demo-less take someone paid the demo for.
            throw new \RuntimeException('The ad is too short to fit the demo clip — lengthen the ad or shorten the demo, then retry. Nothing was charged.');
        }
        $window = round(min($demoLen, $avail), 2);
        $start = round($head + max(0.0, ($avail - $window) / 2), 2);
        $end = round($start + $window, 2);

        $graph = sprintf(
            '[1:v]trim=0:%s,setpts=PTS-STARTPTS,scale=%d:%d:force_original_aspect_ratio=decrease,'
            .'pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=black,setpts=PTS+%s/TB[demo];'
            .'[0:v][demo]overlay=enable=\'between(t,%s,%s)\':x=0:y=0[outv]',
            $window, $w, $h, $w, $h, $start, $start, $end,
        );

        $out = tempnam(sys_get_temp_dir(), 'oneshot-demo-out-').'.mp4';
        $temps[] = $out;
        $run = Process::timeout(600)->run([
            'ffmpeg', '-y', '-i', $basePath, '-i', $demoPath,
            '-filter_complex', $graph, '-map', '[outv]', '-map', '0:a?',
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '19', '-c:a', 'aac', $out,
        ]);
        if (! $run->successful() || ! filesize($out)) {
            throw new \RuntimeException('The demo clip could not be composited into the ad.');
        }

        return $out;
    }

    /** Make one segment on the provider (one automatic retry on a bare transport error) and download it. */
    private function generateSegment(ReplicateVeoAdapter $veo, Scene $scene, int $i, array $chunk, ?string $startFrame, array &$temps): string
    {
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
            // Omni has no seed, so the reference set is all that keeps
            // the presenter recognisable from one chunk to the next —
            // it rides every chunk, as veo_hq's does.
            $chunkRefs = ($this->engine === 'veo_hq' || $this->engine === 'omni' || $i === 0)
                ? $this->referenceImages
                : [];
            $predictionId = $veo->start((string) $chunk['prompt'], (int) $chunk['seconds'], $startFrame, $this->engine, $chunkRefs, $this->seed, $this->resolution);
            $scene->forceFill(['image_generation_settings_json' => array_merge(
                $scene->refresh()->image_generation_settings_json ?? [],
                // generation_started_at is the heartbeat the stuck-generation reaper reads.
                ['oneshot_segment' => $i + 1, 'oneshot_total' => count($this->chunks), 'oneshot_prediction_id' => $predictionId, 'generation_started_at' => now()->toIso8601String()],
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

        return $path;
    }

    /** Whether this job's run still owns the take. */
    private function owns(Scene $scene): bool
    {
        $token = ($scene->image_generation_settings_json ?? [])['oneshot_token'] ?? null;
        return $this->runToken === null || $token === null || $token === $this->runToken;
    }

    /** Where a take's saved segments and recipe live until it is delivered. */
    public static function partsDir(int $workspaceId, int $sceneId): string
    {
        return sprintf('workspaces/%d/ugc-oneshot-parts/%d', $workspaceId, $sceneId);
    }

    /** What makes the segments: a saved one is reused only while all of this is unchanged. */
    private function recipeHash(): string
    {
        return substr(hash('sha256', json_encode([$this->chunks, $this->engine, $this->seed, $this->resolution, $this->demoStorageUrl,
            hash('sha256', (string) $this->initialStartFrame), hash('sha256', json_encode($this->referenceImages))])), 0, 16);
    }

    /**
     * The segments an earlier attempt saved for this same recipe, by index. Saves the recipe the first time, so a retry
     * can run the job again exactly; a changed recipe drops what was saved.
     *
     * @return array<int, array{i: int, url: string}>
     */
    private function prepareParts(Project $project, Scene $scene, StorageService $storage): array
    {
        $settings = $scene->image_generation_settings_json ?? [];
        $hash = $this->recipeHash();
        if (($settings['oneshot_parts_hash'] ?? null) === $hash && ! empty($settings['oneshot_recipe'])) {
            return array_column($settings['oneshot_parts'] ?? [], null, 'i');
        }
        self::forgetParts($scene, (int) $project->workspace_id);
        $recipe = $storage->put(self::partsDir((int) $project->workspace_id, $this->sceneId).'/recipe-'.$hash.'.json', json_encode([
            'projectId' => $this->projectId, 'sceneId' => $this->sceneId, 'chunks' => $this->chunks, 'credits' => $this->credits,
            'engine' => $this->engine, 'referenceImages' => $this->referenceImages, 'initialStartFrame' => $this->initialStartFrame,
            'seed' => $this->seed, 'resolution' => $this->resolution, 'demoStorageUrl' => $this->demoStorageUrl,
        ]), ['ContentType' => 'application/json']);
        $scene->forceFill(['image_generation_settings_json' => array_merge($scene->refresh()->image_generation_settings_json ?? [],
            ['oneshot_recipe' => $recipe, 'oneshot_parts_hash' => $hash, 'oneshot_parts' => []])])->save();
        return [];
    }

    /** Save a finished segment the moment it lands, so a retry never makes it again. */
    private function savePart(Project $project, Scene $scene, StorageService $storage, int $i, string $path): void
    {
        // A damaged download is never saved, or every retry would reuse it.
        $probe = Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path]);
        if (! $probe->successful() || (float) trim($probe->output()) <= 0) {
            throw new \RuntimeException(sprintf('Segment %d came back damaged. Retry to make it again; nothing was charged.', $i + 1));
        }
        $url = $storage->put(self::partsDir((int) $project->workspace_id, $this->sceneId).'/'.$this->recipeHash().'/'.$i.'.mp4',
            file_get_contents($path), ['ContentType' => 'video/mp4']);
        $settings = $scene->refresh()->image_generation_settings_json ?? [];
        $parts = array_values(array_filter($settings['oneshot_parts'] ?? [], fn ($p) => (int) $p['i'] !== $i));
        $parts[] = ['i' => $i, 'url' => $url];
        $scene->forceFill(['image_generation_settings_json' => array_merge($settings, ['oneshot_parts' => $parts])])->save();
    }

    /** Remove a take's saved segments and recipe, and the record of them. */
    public static function forgetParts(Scene $scene, int $workspaceId): void
    {
        try {
            app(StorageService::class)->deleteDirectory(self::partsDir($workspaceId, (int) $scene->getKey()));
        } catch (\Throwable $e) {
            report($e);
        }
        $settings = $scene->image_generation_settings_json ?? [];
        if (array_intersect_key($settings, array_flip(['oneshot_recipe', 'oneshot_parts', 'oneshot_parts_hash']))) {
            $scene->forceFill(['image_generation_settings_json' => array_diff_key($settings, array_flip(['oneshot_recipe', 'oneshot_parts', 'oneshot_parts_hash']))])->save();
        }
    }

    /**
     * Retry a failed take from the segment that failed. Returns 'resumed'; 'running' while a run is still making it;
     * 'not_resumable' when nothing was saved or it is a Test Pass take (its reservation was handed back when it
     * failed) — the caller then makes it again from the start. The claim is made under the scene's row lock with a new
     * run token, so two retries at once start one run, and any older run stops without saving or charging.
     */
    public static function resume(Scene $scene): string
    {
        $claim = DB::transaction(function () use ($scene) {
            DB::table('scenes')->where('id', $scene->getKey())->lockForUpdate()->value('id');
            $scene->refresh();
            $settings = $scene->image_generation_settings_json ?? [];
            if (! empty($settings['in_progress'])) return 'running';
            $project = Project::query()->find($scene->project_id);
            if (! $project || empty($settings['oneshot_recipe']) || empty($settings['last_error'])) return 'not_resumable';
            if (app(CreditService::class)->planTier((int) $project->workspace_id) === 'ugc_pass') return 'not_resumable';
            $recipe = json_decode((string) app(StorageService::class)->get($settings['oneshot_recipe']), true);
            if (! is_array($recipe) || (int) ($recipe['sceneId'] ?? 0) !== (int) $scene->getKey() || empty($recipe['chunks'])) return 'not_resumable';
            $token = (string) Str::uuid();
            $scene->forceFill(['image_generation_settings_json' => array_merge($settings, [
                'last_error' => null, 'in_progress' => true, 'generation_started_at' => now()->toIso8601String(), 'oneshot_token' => $token,
            ])])->save();
            $project->forceFill(['status' => 'generating'])->save();
            self::dispatch(
                (int) $recipe['projectId'], (int) $recipe['sceneId'], $recipe['chunks'], (int) $recipe['credits'], (string) $recipe['engine'],
                $recipe['referenceImages'] ?? [], $recipe['initialStartFrame'] ?? null, $recipe['seed'] ?? null,
                (string) ($recipe['resolution'] ?? '720p'), $recipe['demoStorageUrl'] ?? null, $token,
            )->afterCommit();
            return 'resumed';
        });
        return $claim;
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

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
        ?string $runToken = null,
        ?bool $checkFace = null,
    ) {
        $this->runToken = $runToken;
        $this->checkFace = (bool) $checkFace;
        // Its own queue and worker (worker-ugc): a 30-minute take held up every voiceover and image behind it (QUEUE-REAP).
        $this->onQueue('ugc');
    }

    /** Which run owns the take (oneshot_token on the scene). A job whose token is no longer the scene's — a resumed or
     *  redelivered copy — stops without saving, failing or charging. Null: queued before tokens existed. A plain
     *  property with a default, not a promoted readonly one: a job queued before the deploy unserializes without it,
     *  and reading an uninitialized readonly property throws (review, 2026-10-10). */
    public ?string $runToken = null;

    /** A cast presenter (the first reference image) whose face each clip is checked against, and made again once if it
     *  is someone else. A plain property for the same reason as runToken. */
    public bool $checkFace = false;

    public function handle(ReplicateVeoAdapter $veo, StorageService $storage, CreditService $credits): void
    {
        $project = Project::query()->find($this->projectId);
        $scene = Scene::query()->find($this->sceneId);
        if (! $project || ! $scene || $this->chunks === [] || ! $this->owns($scene)) {
            return;
        }
        \App\Services\Usage\UsageContext::set((int) $project->workspace_id, (int) $project->id);
        // Timed from now: a take queued behind another was reaped before it began (QUEUE-REAP).
        \App\Support\SceneRun::started($scene);

        $temps = [];
        try {
            $segmentPaths = [];
            $startFrame = $this->initialStartFrame;
            $saved = $this->prepareParts($project, $scene, $storage);
            // Saved clips are reused only up to the first one that must be made again: a later saved clip started from
            // the old clip's last frame and would not join the new one.
            $reuse = [];
            foreach (array_keys($this->chunks) as $i) {
                $bytes = isset($saved[$i]) ? self::parts()->get($saved[$i]['url']) : null;
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
            $fromTail = [];
            $faceRetries = 0;
            foreach ($this->chunks as $i => $chunk) {
                // A clip that opens on a new shot starts from the presenter and product photos when the engine takes
                // them: the cut hides the change, and identity and packaging are re-anchored instead of drifting from
                // clip to clip (2026-10-10: a face changed across a before/after). A clip carrying a shot on starts
                // from the last frame, and that duplicate first frame is dropped when the clips are joined.
                $fromRefs = $i > 0 && ! empty($chunk['new_shot']) && $this->referenceImages !== [] && in_array($this->engine, ['omni', 'veo_hq'], true);
                // The previous clip's last frame rides along as a reference for hair, clothes and setting; the
                // presenter's photo alone re-imagined them (2026-10-10: tied hair came back loose after a cutaway).
                $continuity = $fromRefs ? $startFrame : null;
                if ($fromRefs) $startFrame = null;
                $fromTail[$i] = $i > 0 && ! $fromRefs;
                // A segment saved by an earlier attempt is used as it is; the next one starts from its last frame.
                if (isset($reuse[$i])) {
                    $path = tempnam(sys_get_temp_dir(), 'oneshot-').'.mp4';
                    $temps[] = $path;
                    file_put_contents($path, $reuse[$i]);
                } else {
                    if (! $this->owns($scene->refresh())) return; // superseded: the newer run makes the rest
                    $path = $this->generateSegment($veo, $scene, $i, $chunk, $startFrame, $temps, $continuity);
                    if (! $this->owns($scene->refresh())) return; // superseded while this clip was made
                    // Resemblance (owner, 2026-10-11): each cast clip's face is scored against the presenter's photo. With
                    // remakes on (services.ugc.face_retry), a clip under the pass mark is made again once, at our cost, and
                    // the closer of the two is kept; at most two remakes a take. A remake whose check could not run is
                    // never preferred to a clip that was checked.
                    if ($this->checkFace && $this->referenceImages !== []) {
                        $checker = app(\App\Services\Ugc\UgcFaceCheck::class);
                        $score = $checker->score($this->referenceImages[0], $path);
                        if ($score !== null && $score < \App\Services\Ugc\UgcFaceCheck::PASS && $faceRetries < 2 && config('services.ugc.face_retry')) {
                            if (! $this->owns($scene->refresh())) return; // superseded while the check ran: no paid remake
                            $faceRetries++;
                            \Illuminate\Support\Facades\Log::info('one-shot: presenter did not match, clip made again', ['project_id' => $this->projectId, 'segment' => $i + 1, 'score' => $score]);
                            $again = $this->generateSegment($veo, $scene, $i, $chunk, $startFrame, $temps, $continuity);
                            if (! $this->owns($scene->refresh())) return;
                            $second = $checker->score($this->referenceImages[0], $again);
                            if ($second !== null && $second > $score) { $path = $again; $score = $second; }
                        }
                        $this->noteFace($scene, $i, $score);
                    }
                    // A new shot (or the opening) may lose its dead air at the start: a cut hides it.
                    $path = $this->trimToScript($path, (string) ($chunk['dialogue'] ?? ''), $i, $temps, $i === 0 || $fromRefs, $i === count($this->chunks) - 1);
                    $this->savePart($project, $scene, $storage, $i, $path);
                }
                $segmentPaths[] = $path;

                // The tail frame anchors the next segment's identity.
                if ($i < count($this->chunks) - 1) {
                    $framePath = tempnam(sys_get_temp_dir(), 'oneshot-frame-').'.png';
                    $temps[] = $framePath;
                    // The very last frame (each decoded frame overwrites the file): an earlier one made the next clip
                    // replay a few frames of motion at every join.
                    $result = Process::timeout(60)->run(['ffmpeg', '-y', '-sseof', '-0.5', '-i', $path, '-update', '1', '-q:v', '2', $framePath]);
                    if (! $result->successful() || ! filesize($framePath)) {
                        throw new \RuntimeException(sprintf('Could not read the handoff frame after segment %d.', $i + 1));
                    }
                    $startFrame = 'data:image/png;base64,'.base64_encode(file_get_contents($framePath));
                }
            }

            $finalPath = $this->concat($segmentPaths, $temps, $fromTail);

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

            // Word timings for the captions, read from the video's own speech before it counts as ready. A failed
            // transcription never fails the take: captions then fall back to even timing.
            try {
                (new TranscribeAssetJob((int) $asset->id))->handle(app(\App\Services\Media\MediaTranscriptionService::class));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('one-shot: caption timing failed', ['project_id' => $this->projectId, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }

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
                    // The recipe goes with delivery, in the same step: a failure after this point can never resume
                    // (and charge) a delivered take.
                    'image_generation_settings_json' => array_diff_key(array_merge(
                        $scene->image_generation_settings_json ?? [],
                        ['in_progress' => false, 'last_error' => null],
                    ), array_flip(['oneshot_recipe', 'oneshot_parts', 'oneshot_parts_hash'])),
                ])->save();

                $project->forceFill(['status' => 'ready_for_review'])->save();

                // Money moves only now, with the finished video stored.
                $charged = $credits->deduct($project->workspace_id, $this->credits, 'ugc_oneshot', [
                    'project_id' => $this->projectId, 'scene_id' => $this->sceneId,
                    'segments' => count($this->chunks),
                ]);
                if ($charged === false) {
                    // Delivered but not paid (the balance went between start and finish): the team hears of it.
                    \Illuminate\Support\Facades\Log::error('one-shot: delivered without charge', ['project_id' => $this->projectId,
                        'workspace_id' => $project->workspace_id, 'credits' => $this->credits]);
                    report(new \RuntimeException('UGC one-take '.$this->projectId.' delivered without charge ('.$this->credits.' credits).'));
                }
                return true;
            });
            if (! $delivered) {
                return;
            }

            // Delivered: the saved segment files have done their job.
            try { self::parts()->deleteDirectory(self::partsDir((int) $project->workspace_id, $this->sceneId)); } catch (\Throwable $e) { report($e); }
        } finally {
            foreach ($temps as $t) {
                @unlink($t);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Scene::query()->whereKey($this->sceneId)->get()
            ->filter(fn (Scene $scene) => $this->owns($scene) && ! ($scene->status === 'ready' && $scene->visual_asset_id))
            ->each(function (Scene $scene) use ($e): void {
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
    private function generateSegment(ReplicateVeoAdapter $veo, Scene $scene, int $i, array $chunk, ?string $startFrame, array &$temps, ?string $continuity = null): string
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
            // A new shot: the presenter (first), the previous shot's last frame, then the product; the engine takes 3.
            if ($continuity !== null && $chunkRefs !== []) {
                $chunkRefs = array_slice(array_merge([$chunkRefs[0], $continuity], array_slice($chunkRefs, 1)), 0, 3);
            }
            $predictionId = $veo->start((string) $chunk['prompt'], (int) $chunk['seconds'], $startFrame, $this->engine, $chunkRefs, $this->seed, $this->resolution);
            $scene->forceFill(['image_generation_settings_json' => array_merge(
                $scene->refresh()->image_generation_settings_json ?? [],
                // generation_started_at is the heartbeat the stuck-generation reaper reads.
                ['oneshot_segment' => $i + 1, 'oneshot_total' => count($this->chunks), 'oneshot_prediction_id' => $predictionId] + \App\Support\SceneRun::beat(),
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

    /**
     * Cut a clip to what was scripted for it, read from its own transcript: speech the model added before the first
     * scripted word or after the last one is trimmed, so the join lands right after the real last word and the next
     * clip never sounds like a repeat (owner, 2026-10-10). Speech in the middle is left alone. Any doubt (no words, a
     * placeholder transcript, lines that cannot be found) keeps the clip as made.
     */
    /** A line's words for matching: lower case, split at anything not a letter or digit, "okay" read as "ok". */
    public static function tokens(string $text): array
    {
        return array_values(array_filter(array_map(fn ($t) => $t === 'okay' ? 'ok' : $t, preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: []), fn ($t) => $t !== ''));
    }

    /**
     * Where a clip's scripted line sits in its transcript: the first and last spoken word (indices into $said), or null
     * when it cannot be found with confidence. Tolerant of what transcripts do to a line ("Okay" as "OK", "fragrance-free"
     * as two words, a slightly misheard word); the earliest full reading wins, so a repeat after it is left out.
     *
     * @param  array<int, array{text?: string, word?: string, start: float, end: float}>  $said
     * @return array{first: int, last: int}|null
     */
    public static function scriptSpan(array $said, string $dialogue): ?array
    {
        $want = self::tokens($dialogue);
        $n = count($want);
        if ($n < 2) return null;
        $toks = [];
        foreach ($said as $wi => $w) foreach (self::tokens((string) ($w['text'] ?? $w['word'] ?? '')) as $t) $toks[] = [$t, $wi];
        $m = count($toks);
        $eq = fn (string $a, string $b) => $a === $b || (min(strlen($a), strlen($b)) >= 4 && levenshtein($a, $b) <= 1);
        // Start on any of the line's first three words (the opening one may be misheard), then follow the line forward,
        // allowing a couple of stray words between scripted ones.
        for ($s = 0; $s < $m; $s++) {
            for ($a = 0; $a < min(3, $n); $a++) {
                if (! $eq($toks[$s][0], $want[$a])) continue;
                $k = $s; $matched = 1; $lastWant = $a;
                for ($w = $a + 1; $w < $n; $w++) {
                    for ($j = $k + 1; $j <= min($m - 1, $k + 3); $j++) {
                        if ($eq($toks[$j][0], $want[$w])) { $k = $j; $matched++; $lastWant = $w; break; }
                    }
                }
                if ($matched < 0.8 * $n || $lastWant < $n - 2) continue;
                $last = $toks[$k][1];
                // The closing word misheard: the spoken word right after the last match is it.
                if ($lastWant === $n - 2 && isset($said[$last + 1])) $last++;

                // Started on a later word of the line: the words before it are its misheard opening, kept.
                return ['first' => max(0, $toks[$s][1] - $a), 'last' => $last];
            }
        }

        return null;
    }

    private function trimToScript(string $path, string $dialogue, int $i, array &$temps, bool $leadIn = false, bool $ending = false): string
    {
        if (count(self::tokens($dialogue)) < 2) return $path;
        try {
            $t = app(\App\Services\Media\MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps($path, 'video/mp4');
        } catch (\Throwable $e) {
            report($e);
            return $path;
        }
        $said = array_values(array_filter($t['words'] ?? [], fn ($w) => self::tokens((string) ($w['text'] ?? $w['word'] ?? '')) !== []));
        if (($t['provider_key'] ?? '') === 'local_fallback' || count($said) < 2) return $path;
        $span = self::scriptSpan($said, $dialogue);
        if ($span === null) return $path;
        [$first, $last] = [$span['first'], $span['last']];

        $length = (float) trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path])->output());
        // Extra words before the first scripted one always go; a silent lead-in goes only where a cut hides it.
        $from = $first > 0 ? max(0.0, (float) $said[$first]['start'] - 0.12)
            : ($leadIn && (float) $said[$first]['start'] > 0.6 ? (float) $said[$first]['start'] - 0.25 : 0.0);
        // Transcripts mark a word's end early, more so a trailing word ("softer."): 0.4 s clipped the last syllable
        // (2026-10-10). A join keeps 0.6 s, the ad's ending a full second.
        $to = min($length, (float) $said[$last]['end'] + ($ending ? 1.0 : 0.6));
        // Never into a word said after the line (owner, 2026-10-12: each clip is a scene with its own statement): take
        // 281 read "fragrance-free" off the label straight after its line, and the next clip said it again.
        if (isset($said[$last + 1])) $to = min($to, max((float) $said[$last]['end'] + 0.08, (float) $said[$last + 1]['start'] - 0.05));
        if ($length <= 0 || ($from < 0.05 && $length - $to < 0.3) || $to - $from < 1.0) return $path;

        $out = tempnam(sys_get_temp_dir(), 'oneshot-trim-').'.mp4';
        $temps[] = $out;
        $run = Process::timeout(300)->run(['ffmpeg', '-y', '-ss', sprintf('%.3f', $from), '-to', sprintf('%.3f', $to), '-i', $path,
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '18', '-c:a', 'aac', $out]);
        if (! $run->successful() || ! filesize($out)) return $path;
        \Illuminate\Support\Facades\Log::info('one-shot: clip trimmed to its script', ['project_id' => $this->projectId, 'segment' => $i + 1,
            'from' => round($from, 2), 'to' => round($to, 2), 'was' => round($length, 2)]);
        return $out;
    }

    /** Where a take's saved segments and recipe live until it is delivered. */
    /**
     * Where a take's saved segments and recipe live: the private local disk (on prod the api_private volume shared by
     * the API, the workers and the scheduler), never the media bucket, which serves public reads. The recipe holds the
     * presenter's and product photos.
     */
    public static function partsDir(int $workspaceId, int $sceneId): string
    {
        return sprintf('ugc-oneshot-parts/%d/%d', $workspaceId, $sceneId);
    }

    private static function parts(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return \Illuminate\Support\Facades\Storage::disk('local');
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
        $recipe = self::partsDir((int) $project->workspace_id, $this->sceneId).'/recipe-'.$hash.'.json';
        self::parts()->put($recipe, json_encode([
            'projectId' => $this->projectId, 'sceneId' => $this->sceneId, 'chunks' => $this->chunks, 'credits' => $this->credits,
            'engine' => $this->engine, 'referenceImages' => $this->referenceImages, 'initialStartFrame' => $this->initialStartFrame,
            'seed' => $this->seed, 'resolution' => $this->resolution, 'demoStorageUrl' => $this->demoStorageUrl, 'checkFace' => $this->checkFace,
        ]));
        $scene->forceFill(['image_generation_settings_json' => array_merge($scene->refresh()->image_generation_settings_json ?? [],
            ['oneshot_recipe' => $recipe, 'oneshot_parts_hash' => $hash, 'oneshot_parts' => []])])->save();
        return [];
    }

    /** Each clip's resemblance score on the scene, so the take's record shows how close the presenter stayed. */
    private function noteFace(Scene $scene, int $i, ?int $score): void
    {
        $settings = $scene->refresh()->image_generation_settings_json ?? [];
        $scores = (array) ($settings['face_scores'] ?? []);
        $scores[(string) ($i + 1)] = $score;
        $scene->forceFill(['image_generation_settings_json' => array_merge($settings, ['face_scores' => $scores])])->save();
    }

    /** Save a finished segment the moment it lands, so a retry never makes it again. */
    private function savePart(Project $project, Scene $scene, StorageService $storage, int $i, string $path): void
    {
        // A damaged download is never saved, or every retry would reuse it.
        $probe = Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path]);
        if (! $probe->successful() || (float) trim($probe->output()) <= 0) {
            throw new \RuntimeException(sprintf('Segment %d came back damaged. Retry to make it again; nothing was charged.', $i + 1));
        }
        $url = self::partsDir((int) $project->workspace_id, $this->sceneId).'/'.$this->recipeHash().'/'.$i.'.mp4';
        self::parts()->put($url, file_get_contents($path));
        $settings = $scene->refresh()->image_generation_settings_json ?? [];
        $parts = array_values(array_filter($settings['oneshot_parts'] ?? [], fn ($p) => (int) $p['i'] !== $i));
        $parts[] = ['i' => $i, 'url' => $url];
        $scene->forceFill(['image_generation_settings_json' => array_merge($settings, ['oneshot_parts' => $parts])])->save();
    }

    /** Remove a take's saved segments and recipe, and the record of them. */
    public static function forgetParts(Scene $scene, int $workspaceId): void
    {
        try {
            self::parts()->deleteDirectory(self::partsDir($workspaceId, (int) $scene->getKey()));
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
     * 'short' when the balance can no longer pay for it;
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
            if ($scene->status === 'ready' && $scene->visual_asset_id) return 'not_resumable'; // delivered
            $project = Project::query()->find($scene->project_id);
            if (! $project || empty($settings['oneshot_recipe']) || empty($settings['last_error'])) return 'not_resumable';
            if (app(CreditService::class)->planTier((int) $project->workspace_id) === 'ugc_pass') return 'not_resumable';
            $recipe = json_decode((string) self::parts()->get($settings['oneshot_recipe']), true);
            if (! is_array($recipe) || (int) ($recipe['sceneId'] ?? 0) !== (int) $scene->getKey() || empty($recipe['chunks'])) return 'not_resumable';
            // The take is charged on delivery: it only resumes when the balance can still pay for it.
            if (app(CreditService::class)->balance((int) $project->workspace_id) < (int) $recipe['credits']) return 'short';
            $token = (string) Str::uuid();
            $scene->forceFill(['image_generation_settings_json' => array_merge($settings, [
                'last_error' => null, 'in_progress' => true, 'generation_started_at' => now()->toIso8601String(), 'oneshot_token' => $token,
            ])])->save();
            $project->forceFill(['status' => 'generating'])->save();
            self::dispatch(
                (int) $recipe['projectId'], (int) $recipe['sceneId'], $recipe['chunks'], (int) $recipe['credits'], (string) $recipe['engine'],
                $recipe['referenceImages'] ?? [], $recipe['initialStartFrame'] ?? null, $recipe['seed'] ?? null,
                (string) ($recipe['resolution'] ?? '720p'), $recipe['demoStorageUrl'] ?? null, $token, (bool) ($recipe['checkFace'] ?? false),
            )->afterCommit();
            return 'resumed';
        });
        return $claim;
    }

    /**
     * Join the segments into one video, re-timed. Never a stream copy: Omni returns clips with different time bases
     * (1/90000 and 1/12288 in one take), and a copied join kept their timestamps, so an 18-second take played as 6 or
     * as 87 seconds with its sound out of step (2026-10-10). Each clip is reset to start at zero, set to one frame
     * rate and size, and joined with the concat filter.
     */
    private function concat(array $paths, array &$temps, array $fromTail = []): string
    {
        if (count($paths) === 1) {
            return $paths[0];
        }
        $size = Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0:s=x', $paths[0]]);
        [$w, $h] = array_map('intval', explode('x', trim($size->output())) + [1 => 0]);
        if ($w <= 0 || $h <= 0) [$w, $h] = [720, 1280];
        $inputs = [];
        $graph = '';
        $last = count($paths) - 1;
        $silent = [];
        // The clips come first (input i is clip i); a silent clip's stand-in sound is appended after them.
        foreach ($paths as $path) array_push($inputs, '-i', $path);
        $next = count($paths);
        // A clip carried on from the previous one's last frame keeps its light (owner, 2026-10-11: the light shifted at
        // joins). Its colour and brightness are matched to where the previous clip ended, gently and only there: at a
        // cut the picture changes on purpose, so a new shot is left as made.
        $gains = [];
        foreach ($paths as $i => $path) {
            $gains[$i] = [1.0, 1.0, 1.0];
            if ($i > 0 && ! empty($fromTail[$i])) {
                $prev = self::meanRgb($paths[$i - 1], true);
                $cur = self::meanRgb($path, false);
                if ($prev && $cur) $gains[$i] = self::joinGains($prev, $gains[$i - 1], $cur);
            }
        }
        foreach ($paths as $i => $path) {
            $mix = max(array_map(fn ($g) => abs($g - 1), $gains[$i])) > 0.01
                ? vsprintf('colorchannelmixer=rr=%.4f:gg=%.4f:bb=%.4f,', $gains[$i]) : '';
            // A clip that started from the previous clip's last frame repeats it first: drop that frame and its sound.
            $skip = ! empty($fromTail[$i]) ? 'trim=start_frame=1,' : '';
            $askip = ! empty($fromTail[$i]) ? 'atrim=start=0.0417,' : '';
            $len = (float) trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=duration', '-of', 'csv=p=0', $path])->output());
            if ($len <= 0) {
                // A clip returned without sound (a safety filter can strip it): silence of its own length, or the
                // join fails, and every resume with it.
                $vlen = (float) trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path])->output()) ?: 1.0;
                array_push($inputs, '-f', 'lavfi', '-t', sprintf('%.3f', $vlen), '-i', 'anullsrc=r=48000:cl=stereo');
                $silent[$i] = $next++;
            }
            // 30 ms fades at each join, so no click where two clips' sound meets.
            $out = $i < $last ? 0.03 : 0.25; // the ad's last sound fades rather than stopping on a hard edge
            $fades = ($i > 0 ? ',afade=t=in:d=0.03' : '').($len > 0.5 ? sprintf(',afade=t=out:st=%.3f:d=%.2f', $len - ($askip !== '' ? 0.0417 : 0) - $out, $out) : '');
            $graph .= sprintf('[%1$d:v]%4$ssetpts=PTS-STARTPTS,fps=24,scale=%2$d:%3$d:force_original_aspect_ratio=decrease,pad=%2$d:%3$d:(ow-iw)/2:(oh-ih)/2,setsar=1,%8$sformat=yuv420p[v%1$d];'
                .'[%7$s:a]%5$sasetpts=PTS-STARTPTS,aresample=48000,aformat=channel_layouts=stereo%6$s[a%1$d];', $i, $w, $h, $skip, $askip, $fades, $silent[$i] ?? $i, $mix);
        }
        foreach (array_keys($paths) as $i) $graph .= "[v{$i}][a{$i}]";
        $graph .= 'concat=n='.count($paths).':v=1:a=1[outv][outa]';

        $out = tempnam(sys_get_temp_dir(), 'oneshot-final-').'.mp4';
        $temps[] = $out;
        $run = Process::timeout(900)->run(['ffmpeg', '-y', ...$inputs, '-filter_complex', $graph, '-map', '[outv]', '-map', '[outa]',
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '19', '-c:a', 'aac', '-movflags', '+faststart', $out]);
        if (! $run->successful() || ! filesize($out)) {
            throw new \RuntimeException('The segments could not be joined into one video.');
        }

        return $out;
    }

    /** A frame's average colour (red, green, blue, 0-255): the clip's last frame, or the one after its first. */
    private static function meanRgb(string $path, bool $end): ?array
    {
        $seek = $end ? ['-sseof', '-0.2'] : ['-ss', '0.05'];
        $run = Process::timeout(30)->run(['ffmpeg', '-v', 'error', ...$seek, '-i', $path, '-frames:v', '1', '-vf', 'scale=1:1:flags=area', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-']);
        $px = $run->successful() ? $run->output() : '';
        return strlen($px) >= 3 ? array_values(unpack('C3', $px)) : null;
    }

    /**
     * Per-channel gains that bring a clip's opening colour to where the previous clip ended (after that clip's own
     * gains), each within 15%, so a correction never repaints a shot. A near-black channel is left alone.
     *
     * @param  array{0: float, 1: float, 2: float}  $prev  the previous clip's last frame, before its gains
     * @param  array{0: float, 1: float, 2: float}  $prevGain
     * @param  array{0: float, 1: float, 2: float}  $cur  this clip's opening frame
     * @return array{0: float, 1: float, 2: float}
     */
    public static function joinGains(array $prev, array $prevGain, array $cur): array
    {
        return array_map(fn ($p, $g, $c) => $c < 8 ? 1.0 : round(max(0.85, min(1.15, ($p * $g) / $c)), 4), $prev, $prevGain, $cur);
    }
}

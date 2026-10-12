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
        array $composition = [],
        bool $storyboard = false,
        array $look = [],
    ) {
        $this->composition = $composition;
        $this->storyboard = $storyboard;
        $this->look = $look;
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

    public array $composition = [];

    /** The storyboard (UgcStoryboard, staff pilot): a reviewed still per clip, each clip starting from its still and
     *  reviewed in turn. Internal: the user never sees the stills. A plain property for the same reason as runToken. */
    public bool $storyboard = false;

    /** The take's look (UgcLook): wardrobe, place text and whether the place photo rides in the references (at index 2),
     *  so the job dresses the cast presenter once and uses that picture in every clip. Empty: the cast photo as it is. */
    public array $look = [];

    /** The references the clips get: the take's own, with the dressed presenter in place of the cast photo when there is a look. */
    private array $refs = [];

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
            // The last frame of the latest clip the presenter talked in: the room the next shot continues (take 285's
            // kitchen changed after a close-up of hands, whose last frame showed only the counter).
            $roomFrame = null;
            $saved = $this->prepareParts($project, $scene, $storage);
            // Saved clips are reused only up to the first one that must be made again: a later saved clip started from
            // the old clip's last frame and would not join the new one.
            $reuse = [];
            foreach (array_keys($this->chunks) as $i) {
                $bytes = isset($saved[$i]) ? self::parts()->get($saved[$i]['url']) : null;
                if (! is_string($bytes) || $bytes === '') break;
                $reuse[$i] = $bytes;
            }
            $this->refs = $this->look !== [] ? $this->dressed($project, $scene, $storage) : $this->referenceImages;
            $stills = $this->storyboard ? $this->stills($project, $scene, $storage) : [];
            $reviewRemakes = 0;
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
            $carry = null;
            foreach ($this->chunks as $i => $chunk) {
                // What the clip before actually said decides this one's words (carry()); recorded in the trace.
                if ($carry !== null) {
                    $chunk = self::carryOver($chunk, $carry);
                    if ($this->tracing($chunk)) $this->trace($project, $scene, $storage, $i, ['carried' => $carry + ['line' => $chunk['dialogue']]]);
                    $carry = null;
                }
                // A skit clip names its speaker's photos by id; they are read here, only from this workspace or stock.
                if (! empty($chunk['reference_asset_ids']) && empty($chunk['reference_images'])) {
                    $chunk['reference_images'] = $this->referenceUris($chunk['reference_asset_ids'], (int) $project->workspace_id);
                    if ($chunk['reference_images'] === []) throw new \RuntimeException('Segment '.($i + 1).': the speaker\'s photo could not be read. Nothing was charged; try again.');
                }
                // A clip that opens on a new shot starts from the presenter and product photos when the engine takes
                // them: the cut hides the change, and identity and packaging are re-anchored instead of drifting from
                // clip to clip (2026-10-10: a face changed across a before/after). A clip carrying a shot on starts
                // from the last frame, and that duplicate first frame is dropped when the clips are joined.
                $fromRefs = $i > 0 && (! empty($chunk['source_media']) || ! empty($chunk['new_shot'])) && $this->refs !== [] && in_array($this->engine, ['omni', 'veo_hq'], true);
                // The previous clip's last frame rides along as a reference for hair, clothes and setting; the
                // presenter's photo alone re-imagined them (2026-10-10: tied hair came back loose after a cutaway).
                // With a look the room travels in the dressed presenter's picture; the last frame would only crowd out the product.
                $continuity = $fromRefs && empty($chunk['reference_images']) && $this->look === [] ? ($roomFrame ?? $startFrame) : null;
                if ($fromRefs) $startFrame = null;
                // The storyboard's still is the clip's first frame: the room, outfit and starting pose are already in it.
                if (isset($stills[$i]) && empty($chunk['source_media']) && empty($chunk['reference_images'])) {
                    $startFrame = $stills[$i];
                    $continuity = null;
                    $fromRefs = true;
                }
                $fromTail[$i] = $i > 0 && ! $fromRefs && empty($chunk['source_media']) && empty($chunk['reference_images']);
                // A segment saved by an earlier attempt is used as it is; the next one starts from its last frame.
                if (isset($reuse[$i])) {
                    $path = tempnam(sys_get_temp_dir(), 'oneshot-').'.mp4';
                    $temps[] = $path;
                    file_put_contents($path, $reuse[$i]);
                } else {
                    if (! $this->owns($scene->refresh())) return; // superseded: the newer run makes the rest
                    $sourceOnly = ! empty($chunk['source_media']) && trim((string) ($chunk['dialogue'] ?? '')) === '';
                    $path = $sourceOnly
                        ? app(\App\Services\Ugc\UgcComposition::class)->source($chunk['source_media'], $chunk['seconds'], (float) ($chunk['source_offset'] ?? 0), $storage, $temps, ! empty($chunk['requires_audio']))
                        : $this->generateSegment($veo, $scene, $i, $chunk, $startFrame, $temps, $continuity);
                    if (! $this->owns($scene->refresh())) return; // superseded while this clip was made
                    // Resemblance (owner, 2026-10-11): each cast clip's face is scored against the presenter's photo. With
                    // remakes on (services.ugc.face_retry), a clip under the pass mark is made again once, at our cost, and
                    // the closer of the two is kept; at most two remakes a take. A remake whose check could not run is
                    // never preferred to a clip that was checked.
                    if (! $sourceOnly && $this->checkFace && $this->referenceImages !== []) {
                        $checker = app(\App\Services\Ugc\UgcFaceCheck::class);
                        $faceReference = $chunk['reference_images'][0] ?? $this->referenceImages[0];
                        $score = $checker->score($faceReference, $path);
                        if ($score !== null && $score < \App\Services\Ugc\UgcFaceCheck::PASS && $faceRetries < 2 && config('services.ugc.face_retry')) {
                            if (! $this->owns($scene->refresh())) return; // superseded while the check ran: no paid remake
                            $faceRetries++;
                            \Illuminate\Support\Facades\Log::info('one-shot: presenter did not match, clip made again', ['project_id' => $this->projectId, 'segment' => $i + 1, 'score' => $score]);
                            $again = $this->generateSegment($veo, $scene, $i, $chunk, $startFrame, $temps, $continuity);
                            if (! $this->owns($scene->refresh())) return;
                            $second = $checker->score($faceReference, $again);
                            if ($second !== null && $second > $score) { $path = $again; $score = $second; }
                        }
                        $this->noteFace($scene, $i, $score);
                    }
                    // The reviewer: a clip with a timed sheet is asked each block's yes/no question; a storyboard clip without
                    // one is checked against its still. It records its verdict; it remakes a faulted clip once, told what to
                    // fix, at our cost and at most two a take, only when services.ugc.review_remake is on (take 288: an
                    // uncalibrated reviewer faulted every clip and spent both remakes for nothing).
                    if (! $sourceOnly && (! empty($chunk['sheet']) || isset($stills[$i]))) {
                        $review = app(\App\Services\Ugc\UgcReview::class);
                        $wardrobe = (string) ($chunk['wardrobe'] ?? '');
                        $check = fn (string $clip) => ! empty($chunk['sheet']) ? $review->sheet($chunk['sheet'], $clip, $wardrobe)
                            : $review->clip(\App\Services\Ugc\UgcStoryboard::shot((string) $chunk['prompt']), $wardrobe, $clip, $stills[$i], $this->referenceImages[1] ?? null);
                        $verdict = $check($path);
                        $note = ['first' => $verdict];
                        if ($verdict !== null && ! $verdict['ok'] && $reviewRemakes < 2 && config('services.ugc.review_remake')) {
                            if (! $this->owns($scene->refresh())) return;
                            $reviewRemakes++;
                            \Illuminate\Support\Facades\Log::info('one-shot: the reviewer faulted a clip, made again', ['project_id' => $this->projectId, 'segment' => $i + 1, 'problems' => $verdict['problems']]);
                            $fixed = $chunk;
                            $fixed['prompt'] = (string) $chunk['prompt']."\n\nFix these from the last attempt: ".implode(' ', $verdict['problems']);
                            $again = $this->generateSegment($veo, $scene, $i, $fixed, $startFrame, $temps, $continuity);
                            if (! $this->owns($scene->refresh())) return;
                            $second = $check($again);
                            $note['remade'] = $second;
                            if ($second === null || count($second['problems']) <= count($verdict['problems'])) $path = $again;
                        }
                        $this->noteReview($scene, 'clip_reviews', $i, $note);
                    }
                    // A new shot (or the opening) may lose its dead air at the start: a cut hides it.
                    // The trace (owner, 2026-10-12: "keep results of each step"): the clip as the model returned it, before
                    // any trim, with the exact prompt it was given.
                    if ($this->tracing($chunk)) $this->trace($project, $scene, $storage, $i, ['prompt' => (string) $chunk['prompt'], 'sheet' => $chunk['sheet'] ?? null,
                        'seconds_asked' => $chunk['seconds'] ?? null, 'seconds_made' => self::seconds($path)], $path);
                    $heard = $this->checkSpeech($path, (string) ($chunk['dialogue'] ?? ''), $i);
                    $keyColour = $this->composition['key_color'] ?? '0x00FF00';
                    if (! $sourceOnly && ($this->composition['layout'] ?? '') === 'green_screen' && ! \App\Services\Ugc\UgcComposition::hasGreenBackground($path, $keyColour)) {
                        // One remake at our cost when the model drew scenery instead of the backdrop (#5); a second miss
                        // stops the take rather than leaving the customer to pay for the same failure again.
                        if (! $this->owns($scene->refresh())) return;
                        \Illuminate\Support\Facades\Log::info('one-shot: no key backdrop, clip made again', ['project_id' => $this->projectId, 'segment' => $i + 1]);
                        $again = $this->generateSegment($veo, $scene, $i, $chunk, $startFrame, $temps, $continuity);
                        if (! $this->owns($scene->refresh())) return;
                        $heard = $this->checkSpeech($again, (string) ($chunk['dialogue'] ?? ''), $i);
                        if (! \App\Services\Ugc\UgcComposition::hasGreenBackground($again, $keyColour)) {
                            throw new \RuntimeException('Segment '.($i + 1).' came back without the plain backdrop twice, so the presenter cannot be placed over your page. Nothing was charged. Try again, or make it as a testimonial instead.');
                        }
                        $path = $again;
                    }
                    if (! empty($chunk['requires_audio'])) {
                        $audio = Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=index', '-of', 'csv=p=0', $path]);
                        if (! $audio->successful() || trim($audio->output()) === '') throw new \RuntimeException('The ASMR clip has no native audio. Retry this segment before delivery; nothing was charged.');
                    }
                    if (! $sourceOnly && ! empty($chunk['source_media'])) {
                        $media = $chunk['source_media'] + ['offset' => $chunk['source_offset'] ?? 0];
                        $path = app(\App\Services\Ugc\UgcComposition::class)->render($path, ['layout' => 'replace', 'assets' => [$media]], $storage, $temps);
                    }
                    $this->savePart($project, $scene, $storage, $i, $path);
                    if (isset($heard) && $heard !== null && isset($this->chunks[$i + 1])) $carry = self::carry($heard, (string) ($chunk['dialogue'] ?? ''), (string) ($this->chunks[$i + 1]['dialogue'] ?? ''));
                    $heard = null;
                    if ($this->tracing($chunk)) $this->trace($project, $scene, $storage, $i, ['seconds_kept' => self::seconds($path)]);
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
                    if (trim((string) ($chunk['dialogue'] ?? '')) !== '') $roomFrame = $startFrame;
                }
            }

            $finalPath = $this->concat($segmentPaths, $temps, $fromTail);

            // Post-composite: splice the ACTUAL demo clip full-frame over a
            // mid-ad window while the presenter's voice keeps playing — the
            // real recording, crisp and unchanged, not a model recreation.
            if ($this->demoStorageUrl !== null) {
                $finalPath = $this->compositeDemo($finalPath, $this->demoStorageUrl, $storage, $temps);
            }

            if ($this->composition !== []) $finalPath = app(\App\Services\Ugc\UgcComposition::class)->render($finalPath, $this->composition, $storage, $temps);

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

    /**
     * The storyboard's stills: the saved ones when this take already made them (a resumed run), else they are made,
     * each reviewed and remade once if faulted, then saved. Internal only (owner, 2026-10-12: the user never sees it).
     *
     * @return list<string> data URIs, one per clip
     */
    private function stills(Project $project, Scene $scene, StorageService $storage): array
    {
        $dir = self::partsDir((int) $project->workspace_id, $this->sceneId).'/'.$this->recipeHash().'/stills';
        $saved = [];
        foreach (array_keys($this->chunks) as $i) {
            $bytes = self::parts()->exists($dir.'/'.$i.'.txt') ? self::parts()->get($dir.'/'.$i.'.txt') : null;
            if (! is_string($bytes) || $bytes === '') { $saved = []; break; }
            $saved[] = $bytes;
        }
        if ($saved !== []) return $saved;

        $board = app(\App\Services\Ugc\UgcStoryboard::class);
        $stills = $board->make($this->chunks, $this->refs);
        $paths = [];
        foreach ($stills as $i => $uri) {
            self::parts()->put($dir.'/'.$i.'.txt', $uri);
            // A copy the team can look at; never shown to the user.
            $path = sprintf('workspaces/%d/assets/ugc-storyboard/%d/%s-%d.png', $project->workspace_id, $this->sceneId, $this->recipeHash(), $i);
            $storage->put($path, base64_decode(substr($uri, strpos($uri, ',') + 1)), ['ContentType' => 'image/png']);
            $paths[] = $path;
        }
        $scene->forceFill(['image_generation_settings_json' => array_merge($scene->refresh()->image_generation_settings_json ?? [],
            ['storyboard_stills' => $paths, 'storyboard_reviews' => $board->notes])])->save();

        return $stills;
    }

    /**
     * The take's references with the cast photo replaced by the presenter dressed for this ad in its own version of the
     * place (UgcLook), made once and saved with the take, so a resumed run uses the same picture; the place photo then
     * leaves the references, since the room is in the picture. Without a look that could be made, the take's own.
     *
     * @return list<string>
     */
    private function dressed(Project $project, Scene $scene, StorageService $storage): array
    {
        $refs = $this->referenceImages;
        $plateAt = isset($this->look['plate_at']) ? (int) $this->look['plate_at'] : null;
        $plate = $plateAt !== null ? ($refs[$plateAt] ?? null) : null;
        if ($refs === []) return $refs;
        $file = self::partsDir((int) $project->workspace_id, $this->sceneId).'/'.$this->recipeHash().'/look.txt';
        $uri = self::parts()->exists($file) ? (string) self::parts()->get($file) : '';
        if ($uri === '') {
            $made = app(\App\Services\Ugc\UgcLook::class)->make($refs[0], $plate, (string) ($this->look['wardrobe'] ?? ''), (string) ($this->look['place'] ?? ''));
            if ($made === null) return $refs;
            $uri = $made['uri'];
            self::parts()->put($file, $uri);
            // A copy the team can look at; never shown to the user.
            $path = sprintf('workspaces/%d/assets/ugc-look/%d/%s.png', $project->workspace_id, $this->sceneId, $this->recipeHash());
            $storage->put($path, base64_decode(substr($uri, strpos($uri, ',') + 1)), ['ContentType' => 'image/png']);
            $scene->forceFill(['image_generation_settings_json' => array_merge($scene->refresh()->image_generation_settings_json ?? [],
                ['look' => ['path' => $path, 'face_score' => $made['score']]])])->save();
        }
        if ($plateAt !== null) unset($refs[$plateAt]);
        $refs[0] = $uri;

        return array_values($refs);
    }

    /** Staff pilots keep a trace of every step; customer takes do not. */
    private function tracing(array $chunk): bool
    {
        return $this->look !== [] || $this->storyboard || ! empty($chunk['sheet']);
    }

    /**
     * One step of the take on its record, kept after delivery (the working files are deleted then): what a clip was asked
     * and what came back, so a weak take shows where it went wrong. A raw clip is copied where the team can watch it.
     */
    private function trace(Project $project, Scene $scene, StorageService $storage, int $i, array $data, ?string $clip = null): void
    {
        try {
            if ($clip !== null && is_file($clip)) {
                $data['raw_clip'] = sprintf('workspaces/%d/assets/ugc-trace/%d/%s/clip-%d.mp4', $project->workspace_id, $this->sceneId, $this->recipeHash(), $i + 1);
                $storage->put($data['raw_clip'], (string) file_get_contents($clip), ['ContentType' => 'video/mp4']);
            }
            $settings = $scene->refresh()->image_generation_settings_json ?? [];
            $trace = (array) ($settings['trace'] ?? []);
            $trace[(string) ($i + 1)] = array_merge((array) ($trace[(string) ($i + 1)] ?? []), $data);
            $scene->forceFill(['image_generation_settings_json' => array_merge($settings, ['trace' => $trace])])->save();
        } catch (\Throwable $e) {
            report($e); // the trace never stops a take
        }
    }

    private static function seconds(string $path): ?float
    {
        $s = (float) trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path])->output());
        return $s > 0 ? round($s, 2) : null;
    }

    /** A reviewer's verdict on one clip, on the take's record. */
    private function noteReview(Scene $scene, string $key, int $i, array $note): void
    {
        $settings = $scene->refresh()->image_generation_settings_json ?? [];
        $all = (array) ($settings[$key] ?? []);
        $all[(string) ($i + 1)] = $note;
        $scene->forceFill(['image_generation_settings_json' => array_merge($settings, [$key => $all])])->save();
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
                ? $this->refs
                : [];
            if (! empty($chunk['reference_images'])) { $chunkRefs = $chunk['reference_images']; $startFrame = null; $continuity = null; }
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
        $text = mb_strtolower($text);
        // Accents do not decide a match: "Nécessaire" is often heard as "Necessaire".
        if (class_exists(\Normalizer::class)) $text = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_D));

        return array_values(array_filter(array_map(fn ($t) => $t === 'okay' ? 'ok' : $t, preg_split('/[^\p{L}\p{N}]+/u', $text) ?: []), fn ($t) => $t !== ''));
    }

    /**
     * Where a clip's scripted line sits in its transcript: the first and last spoken word (indices into $said), or null
     * when it cannot be found with confidence. Tolerant of what transcripts do to a line ("Okay" as "OK", "fragrance-free"
     * as two words, a slightly misheard word); the earliest full reading wins, so a repeat after it is left out.
     *
     * @param  array<int, array{text?: string, word?: string, start: float, end: float}>  $said
     * @return array{first: int, last: int}|null
     */
    public static function scriptSpan(array $said, string $dialogue, float $minimumCoverage = 0.8): ?array
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
                // Never infer a missing closing word: cutting on the penultimate word can clip the real ending.
                if ($matched < $minimumCoverage * $n || $lastWant !== $n - 1) continue;
                $last = $toks[$k][1];

                // Started on a later word of the line: the words before it are its misheard opening, kept.
                return ['first' => max(0, $toks[$s][1] - $a), 'last' => $last];
            }
        }

        return null;
    }

    /** Never deliver a spoken clip based on a placeholder or an unfinished transcript. */
    public static function verifiedSpeechSpan(array $transcript, string $dialogue): ?array
    {
        if (($transcript['provider_key'] ?? '') === 'local_fallback') return null;
        $words = array_values(array_filter($transcript['words'] ?? [], fn ($w) => self::tokens((string) ($w['text'] ?? $w['word'] ?? '')) !== []));
        foreach ($words as $word) {
            if (! isset($word['start'], $word['end']) || ! is_numeric($word['start']) || ! is_numeric($word['end']) || $word['start'] < 0 || $word['end'] < $word['start']) return null;
        }
        $wanted = self::tokens($dialogue);
        if (count($wanted) === 1) {
            foreach ($words as $i => $word) if (self::tokens((string) ($word['text'] ?? $word['word'] ?? '')) === $wanted) return ['first' => $i, 'last' => $i];
            return null;
        }
        return self::scriptSpan($words, $dialogue, 0.95);
    }

    /**
     * Every clip is kept exactly as it was made (owner, 2026-10-12: "we aren't meant to trim anything"): the trim after the
     * last word cut take 293's camera being set down, which the timed sheet had planned in that silence. The transcript
     * only checks the line: a clip with no speech for a scripted line fails uncharged; a line heard differently is logged.
     */
    private function checkSpeech(string $path, string $dialogue, int $i): ?array
    {
        if (self::tokens($dialogue) === []) return null;
        // A transcript is a check, not the truth (#1, 2026-10-12): an outage, a placeholder, or a line heard differently
        // ("Nécessaire" as "Necessaire", "$29" as "twenty-nine dollars") keeps the clip as it was made and logs it; failing
        // the take there meant paying for clips a retry would fail the same way. Only a clip with no speech at all fails.
        try {
            $t = app(\App\Services\Media\MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps($path, 'video/mp4');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('one-shot: line not checked, transcription failed', ['project_id' => $this->projectId, 'segment' => $i + 1, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }
        if (($t['provider_key'] ?? '') === 'local_fallback') return null;
        $said = array_values(array_filter($t['words'] ?? [], fn ($w) => self::tokens((string) ($w['text'] ?? $w['word'] ?? '')) !== []));
        if ($said === [] && trim((string) ($t['transcript'] ?? '')) === '') {
            throw new \RuntimeException('Segment '.($i + 1).' came back with no speech although its line is scripted. Retry to remake this segment; the take has not been charged.');
        }
        if ((self::verifiedSpeechSpan($t, $dialogue) ?? self::scriptSpan($said, $dialogue)) === null) {
            \Illuminate\Support\Facades\Log::warning('one-shot: line not matched in the transcript; kept as spoken', ['project_id' => $this->projectId, 'segment' => $i + 1,
                'line' => mb_substr($dialogue, 0, 200), 'heard' => mb_substr((string) ($t['transcript'] ?? ''), 0, 200)]);
        }

        return $t;
    }

    /**
     * What the next clip should say, given what this one actually said (owner, 2026-10-12: nothing is trimmed, so each
     * clip is read and the next one adjusted instead). When this clip ran on into the next line, those words leave the
     * next line, so nothing is said twice; when it left the end of its own line unsaid, those words open the next line.
     * Only clear matches count (two or more words in a row): a transcript mishears words, and a doubt changes nothing.
     *
     * @return array{drop: int, add: string}|null  tokens to drop from the start of the next line, words to add before it
     */
    public static function carry(array $transcript, string $line, string $next): ?array
    {
        $words = array_values(array_filter($transcript['words'] ?? [], fn ($w) => self::tokens((string) ($w['text'] ?? $w['word'] ?? '')) !== []));
        $heard = [];
        foreach ($words as $w) foreach (self::tokens((string) ($w['text'] ?? $w['word'] ?? '')) as $t) $heard[] = $t;
        $planned = self::tokens($line);
        if ($heard === [] || count($planned) < 2) return null;
        $eq = fn (string $a, string $b) => $a === $b || (min(strlen($a), strlen($b)) >= 4 && levenshtein($a, $b) <= 1);

        // Follow the line through what was heard, a few words of slack each step; note where it ends and what went unsaid.
        $j = 0;
        $matched = [];
        foreach ($planned as $pi => $p) {
            $found = null;
            for ($k = $j; $k < min(count($heard), $j + 4); $k++) if ($eq($p, $heard[$k])) { $found = $k; break; }
            $matched[$pi] = $found !== null;
            if ($found !== null) $j = $found + 1;
        }
        if (count(array_filter($matched)) < max(2, (int) ceil(count($planned) * 0.6))) return null; // not this line: unsure

        $tail = 0;
        for ($pi = count($planned) - 1; $pi >= 0 && ! $matched[$pi]; $pi--) $tail++;
        $add = '';
        if ($tail >= 2) {
            $lineWords = preg_split('/\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY);
            $count = 0;
            $kept = [];
            for ($w = count($lineWords) - 1; $w >= 0 && $count < $tail; $w--) { $count += count(self::tokens($lineWords[$w])); array_unshift($kept, $lineWords[$w]); }
            $add = implode(' ', $kept);
        }

        $after = array_slice($heard, $j);
        $nextToks = self::tokens($next);
        $drop = 0;
        for ($k = min(count($after), count($nextToks) - 1); $k >= 2; $k--) {
            if (array_slice($after, 0, $k) === array_slice($nextToks, 0, $k)) { $drop = $k; break; }
        }

        return $drop > 0 || $add !== '' ? ['drop' => $drop, 'add' => $add] : null;
    }

    /** The next clip with its line adjusted by carry(): its words, its timed sheet and its prompt. */
    public static function carryOver(array $chunk, array $carry): array
    {
        $line = trim((string) ($chunk['dialogue'] ?? ''));
        $cut = function (string $text, int $tokens): array {
            $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
            $gone = [];
            while ($tokens > 0 && $words !== []) { $w = array_shift($words); $tokens -= count(self::tokens($w)); $gone[] = $w; }
            return [implode(' ', $words), implode(' ', $gone), max(0, $tokens)];
        };
        [$rest, $gone] = $cut($line, (int) $carry['drop']);
        $newLine = trim(trim((string) $carry['add']).' '.$rest);
        if (self::tokens($newLine) === []) return $chunk;

        if (! empty($chunk['sheet'])) {
            $sheet = $chunk['sheet'];
            $left = (int) $carry['drop'];
            foreach ($sheet['blocks'] as $b => $block) {
                if ($left <= 0) break;
                if (trim((string) ($block['says'] ?? '')) === '') continue;
                [$sheet['blocks'][$b]['says'], , $left] = $cut((string) $block['says'], $left);
            }
            if ($carry['add'] !== '') {
                $first = null;
                foreach ($sheet['blocks'] as $b => $block) if (trim((string) ($block['says'] ?? '')) !== '') { $first = $b; break; }
                $first ??= 0;
                $sheet['blocks'][$first]['says'] = trim($carry['add'].' '.($sheet['blocks'][$first]['says'] ?? ''));
            }
            $chunk['sheet'] = $sheet;
            $chunk['prompt'] = \App\Services\Ugc\UgcActionSheet::prompt($sheet, $newLine);
        } else {
            $prompt = (string) ($chunk['prompt'] ?? '');
            if ($gone !== '' && ($at = mb_strpos($prompt, '"'.$gone)) !== false) $prompt = mb_substr($prompt, 0, $at + 1).ltrim(mb_substr($prompt, $at + 1 + mb_strlen($gone)));
            if ($carry['add'] !== '' && preg_match('/says[^"]*"/u', $prompt, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1] + strlen($m[0][0]);
                $prompt = substr($prompt, 0, $pos).$carry['add'].' '.substr($prompt, $pos);
            }
            $chunk['prompt'] = $prompt;
        }
        $chunk['dialogue'] = $newLine;

        return $chunk;
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

    /** Image assets as data URIs, in the order given; this workspace's own or stock ones only, images only. */
    private function referenceUris(array $assetIds, int $workspaceId): array
    {
        $assets = Asset::query()->whereIn('id', array_map('intval', $assetIds))
            ->where(fn ($q) => $q->where('workspace_id', $workspaceId)->orWhereNull('workspace_id'))->get()->keyBy('id');
        $out = [];
        foreach ($assetIds as $id) {
            $asset = $assets->get((int) $id);
            if (! $asset || ! str_starts_with((string) $asset->mime_type, 'image/') || ! $asset->storage_url) continue;
            $bytes = app(StorageService::class)->get((string) $asset->storage_url);
            if (is_string($bytes) && $bytes !== '' && strlen($bytes) <= 8 * 1024 * 1024) $out[] = 'data:'.$asset->mime_type.';base64,'.base64_encode($bytes);
        }

        return $out;
    }

    /** What makes the segments: a saved one is reused only while all of this is unchanged. */
    private function recipeHash(): string
    {
        $recipe = [$this->chunks, $this->engine, $this->seed, $this->resolution, $this->demoStorageUrl,
            hash('sha256', (string) $this->initialStartFrame), hash('sha256', json_encode($this->referenceImages))];
        // Existing queued recipes keep their hash when no new composition is requested.
        if ($this->composition !== []) $recipe[] = $this->composition;
        if ($this->storyboard) $recipe[] = 'storyboard';
        if ($this->look !== []) $recipe[] = $this->look;
        return substr(hash('sha256', json_encode($recipe)), 0, 16);
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
            'seed' => $this->seed, 'resolution' => $this->resolution, 'demoStorageUrl' => $this->demoStorageUrl, 'checkFace' => $this->checkFace, 'composition' => $this->composition, 'storyboard' => $this->storyboard, 'look' => $this->look,
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
                (string) ($recipe['resolution'] ?? '720p'), $recipe['demoStorageUrl'] ?? null, $token, (bool) ($recipe['checkFace'] ?? false), $recipe['composition'] ?? [], (bool) ($recipe['storyboard'] ?? false), (array) ($recipe['look'] ?? []),
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

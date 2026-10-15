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
 * a single take. No scenes; an Omni line is read by the speaker's fixed voice
 * and lip-synced onto the clip (voiceRead, lipsync), every other engine speaks natively.
 *
 * Paid as it starts, given back if it fails (owner, 2026-10-15): the quote is held when the take is dispatched
 * (hold), refunded when it fails, is reaped or is deleted (releaseHold), and held again when it resumes. A take
 * delivered after the balance ran out used to be free.
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
        string $aspect = '9:16',
    ) {
        $this->aspect = $aspect;
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

    /** A section remake of a delivered take (SECTIONS, owner 2026-10-15): ['from' => i, 'to' => k, 'note' => '']. Clips
     *  from..to are made again (to: the last clip carried straight on from it), every other saved clip is reused. */
    public array $remake = [];

    /** How long a delivered take keeps its clips for section remakes (owner, 2026-10-15: 30 days). */
    public const SECTION_DAYS = 30;

    /** The take's shape (9:16, 1:1 on Seedance, or 16:9). A plain property for the same reason as runToken. */
    public string $aspect = '9:16';

    /** The references the clips get: the take's own, with the dressed presenter in place of the cast photo when there is a look. */
    private array $refs = [];

    /** A close-up of the cast presenter's face from their own photo (UgcFaceCrop), sent beside the dressed picture. */
    private ?string $faceRef = null;

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
                // A section remake makes its clips again and reuses all the others, before and after them.
                if ($this->remake !== [] && $i >= (int) $this->remake['from'] && $i <= (int) $this->remake['to']) continue;
                $bytes = isset($saved[$i]) ? self::parts()->get($saved[$i]['url']) : null;
                if (! is_string($bytes) || $bytes === '') {
                    if ($this->remake !== []) throw new \RuntimeException('This video\'s sections are no longer kept, so one cannot be remade. Nothing was charged.');
                    break;
                }
                $reuse[$i] = $bytes;
            }
            $this->refs = $this->look !== [] ? $this->dressed($project, $scene, $storage) : $this->referenceImages;
            if ($this->checkFace && $this->referenceImages !== []) $this->faceRef = $this->faceClose($project);
            $stills = $this->storyboard ? $this->stills($project, $scene, $storage) : [];
            $reviewRemakes = 0;
            // veo-3.1-fast has no reference-image inputs, so packaging can
            // only reach an exact take through the start frame itself:
            // composite the character holding the product (same /edits
            // pipeline the multi-scene lane uses). Runs on the worker, its
            // small image cost is absorbed, and any failure falls back to
            // the plain character frame.
            // Veo 3.1 Fast takes no photos: a take with a look starts from the dressed presenter, face, clothes and room in one.
            if ($this->engine === 'veo' && $this->look !== [] && $this->refs !== [] && $startFrame === null) $startFrame = $this->refs[0];
            if (! isset($reuse[0]) && $startFrame !== null && $this->referenceImages !== [] && $this->engine === 'veo' && $this->look === []) {
                try {
                    $composite = app(\App\Services\Generation\Image\CharacterImageAdapter::class)->generate(
                        'Vertical 9:16 selfie-framing still of the person from the first reference image naturally holding the product shown in the other reference images at chest height, label facing the camera, casual lived-in setting, soft daylight, authentic phone-camera feel',
                        'realistic', $this->aspect,
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
            // A skit speaker's own photo, read once, for the mix-up check (RD-02).
            $speakerPhotos = [];
            $photoOf = function (int $speaker) use (&$speakerPhotos, $project): ?string {
                if (! array_key_exists($speaker, $speakerPhotos)) {
                    $ids = collect($this->chunks)->first(fn ($c) => (int) ($c['speaker'] ?? 0) === $speaker && ! empty($c['reference_asset_ids']))['reference_asset_ids'] ?? [];
                    $speakerPhotos[$speaker] = $ids ? ($this->referenceUris(array_slice($ids, 0, 1), (int) $project->workspace_id)[0] ?? null) : null;
                }
                return $speakerPhotos[$speaker];
            };
            $carry = null;
            $adapt = null;
            foreach ($this->chunks as $i => $chunk) {
                $clipVerdict = null;
                if ($this->remake !== [] && $i === (int) $this->remake['from'] && trim((string) ($this->remake['note'] ?? '')) !== '') {
                    $chunk['prompt'] = (string) $chunk['prompt']."\n\nWhat to change from the last version of this shot: ".trim((string) $this->remake['note']);
                }
                // What the clip before actually said decides this one's words (carry()); recorded in the trace.
                if ($carry !== null) {
                    $chunk = self::carryOver($chunk, $carry);
                    if ($this->tracing($chunk)) $this->trace($project, $scene, $storage, $i, ['carried' => $carry + ['line' => $chunk['dialogue']]]);
                    $carry = null;
                }
                // How the clip before really ended decides how this one starts (UgcFollowOn, "apply" mode only).
                if ($adapt !== null) {
                    $chunk['sheet'] = $adapt;
                    $chunk['prompt'] = \App\Services\Ugc\UgcActionSheet::prompt($adapt, (string) ($chunk['dialogue'] ?? ''));
                    $adapt = null;
                }
                // A skit clip names its speaker's photos by id; they are read here, only from this workspace or stock.
                if (! empty($chunk['reference_asset_ids']) && empty($chunk['reference_images'])) {
                    $chunk['reference_images'] = $this->referenceUris($chunk['reference_asset_ids'], (int) $project->workspace_id);
                    if ($chunk['reference_images'] === []) throw new \RuntimeException('Segment '.($i + 1).': the speaker\'s photo could not be read. Nothing was charged; try again.');
                    $plate = isset($chunk['place_at']) ? ($this->referenceImages[$chunk['place_at']] ?? null) : null;
                    // Each speaker dressed once, in their own outfit and the ad's room, and that picture used in every one of
                    // their clips (take 306, 2026-10-15: from the cast photo plus a written outfit, clothes changed per clip).
                    $dressed = ! empty($chunk['wardrobe']) ? $this->dressedSpeaker($project, $scene, (int) ($chunk['speaker'] ?? 0), $chunk['reference_images'][0], $plate, (string) $chunk['wardrobe'], (string) ($chunk['place'] ?? '')) : null;
                    if ($dressed !== null) {
                        // The dressed picture carries the face, the clothes and the room: then the product.
                        $chunk['reference_images'] = array_merge([$dressed], array_slice($chunk['reference_images'], 1, 2));
                    } elseif ($plate !== null) {
                        // The place photo, so both speakers of a skit are in one room: the speaker, their product, then the place.
                        $chunk['reference_images'] = array_merge(array_slice($chunk['reference_images'], 0, 2), [$plate]);
                    }
                }
                // A clip that opens on a new shot starts from the presenter and product photos when the engine takes
                // them: the cut hides the change, and identity and packaging are re-anchored instead of drifting from
                // clip to clip (2026-10-10: a face changed across a before/after). A clip carrying a shot on starts
                // from the last frame, and that duplicate first frame is dropped when the clips are joined.
                $fromRefs = $i > 0 && (! empty($chunk['source_media']) || ! empty($chunk['new_shot'])) && $this->refs !== [] && in_array($this->engine, ['omni', 'veo_hq'], true);
                // The previous clip's last frame rides along as a reference for hair, clothes and setting; the
                // presenter's photo alone re-imagined them (2026-10-10: tied hair came back loose after a cutaway).
                // With a look, the last frame of the clip just made rides between the dressed presenter and the product: it is
                // the only picture of the room and the hands as they are now (take 295: the curtains opened between clips and
                // the bottle changed hands). Without one, the last frame of the latest talking clip carries the room.
                $continuity = $fromRefs && empty($chunk['reference_images']) ? ($this->look !== [] ? $startFrame : ($roomFrame ?? $startFrame)) : null;
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
                    // An Omni line is read once by its speaker's voice first, and the clip is asked for the read's length, so
                    // the read can be synced onto it after (owner, 2026-10-14: "for omni we can use lip sync").
                    $read = $sourceOnly ? null : $this->voiceRead($chunk, $i, $project, $temps);
                    if ($read !== null) $chunk['seconds'] = max(2, (int) ceil($read['seconds'] + 0.4));
                    $path = $sourceOnly
                        ? app(\App\Services\Ugc\UgcComposition::class)->source($chunk['source_media'], $chunk['seconds'], (float) ($chunk['source_offset'] ?? 0), $storage, $temps, ! empty($chunk['requires_audio']))
                        : $this->generateSegment($veo, $scene, $i, $chunk, $startFrame, $temps, $continuity);
                    if (! $this->owns($scene->refresh())) return; // superseded while this clip was made
                    // Resemblance (owner, 2026-10-11): each cast clip's face is scored against the presenter's photo. With
                    // remakes on (services.ugc.face_retry), a clip under the pass mark is made again once, at our cost, and
                    // the closer of the two is kept; at most two remakes a take. A remake whose check could not run is
                    // never preferred to a clip that was checked.
                    // A skit's speakers are checked too, each against their own photo (RD-02, 2026-10-15).
                    $skitClip = ! empty($chunk['reference_asset_ids']) && ! empty($chunk['speaker']);
                    if (! $sourceOnly && (($this->checkFace && $this->referenceImages !== []) || $skitClip)) {
                        $checker = app(\App\Services\Ugc\UgcFaceCheck::class);
                        $faceReference = $skitClip ? ($photoOf((int) $chunk['speaker']) ?? $chunk['reference_images'][0]) : ($chunk['reference_images'][0] ?? $this->referenceImages[0]);
                        $score = $checker->score($faceReference, $path);
                        // The mix-up check (from Jellyfish): a clip whose face is closer to the other speaker than to its own
                        // shows the wrong person; it counts as a miss, so a face remake (when on) makes it again.
                        if ($skitClip && $score !== null) {
                            foreach (array_unique(array_map(fn ($c) => (int) ($c['speaker'] ?? 0), $this->chunks)) as $other) {
                                if ($other === 0 || $other === (int) $chunk['speaker'] || ($otherPhoto = $photoOf($other)) === null) continue;
                                $theirs = $checker->score($otherPhoto, $path);
                                if ($theirs !== null && $theirs > $score) {
                                    $this->noteMixup($scene, $i, (int) $chunk['speaker'], $other, $score, $theirs);
                                    $score = min($score, \App\Services\Ugc\UgcFaceCheck::PASS - 1);
                                }
                            }
                        }
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
                        $clipVerdict = $note['remade'] ?? $verdict;
                    }
                    // A new shot (or the opening) may lose its dead air at the start: a cut hides it.
                    // The trace (owner, 2026-10-12: "keep results of each step"): the clip as the model returned it, before
                    // any trim, with the exact prompt it was given.
                    if ($this->tracing($chunk)) $this->trace($project, $scene, $storage, $i, ['prompt' => (string) $chunk['prompt'], 'sheet' => $chunk['sheet'] ?? null,
                        'seconds_asked' => $chunk['seconds'] ?? null, 'seconds_made' => self::seconds($path)], $path);
                    if ($read !== null) $path = $this->lipsync($path, $read, $i, $temps);
                    $heard = $this->checkSpeech($path, (string) ($chunk['dialogue'] ?? ''), $i);
                    $keyColour = $this->composition['key_color'] ?? '0x00FF00';
                    if (! $sourceOnly && ($this->composition['layout'] ?? '') === 'green_screen' && ! \App\Services\Ugc\UgcComposition::hasGreenBackground($path, $keyColour)) {
                        // One remake at our cost when the model drew scenery instead of the backdrop (#5); a second miss
                        // stops the take rather than leaving the customer to pay for the same failure again.
                        if (! $this->owns($scene->refresh())) return;
                        \Illuminate\Support\Facades\Log::info('one-shot: no key backdrop, clip made again', ['project_id' => $this->projectId, 'segment' => $i + 1]);
                        $again = $this->generateSegment($veo, $scene, $i, $chunk, $startFrame, $temps, $continuity);
                        if (! $this->owns($scene->refresh())) return;
                        if (! \App\Services\Ugc\UgcComposition::hasGreenBackground($again, $keyColour)) {
                            throw new \RuntimeException('Segment '.($i + 1).' came back without the plain backdrop twice, so the presenter cannot be placed over your page. Nothing was charged. Try again, or make it as a testimonial instead.');
                        }
                        if ($read !== null) $again = $this->lipsync($again, $read, $i, $temps);
                        $heard = $this->checkSpeech($again, (string) ($chunk['dialogue'] ?? ''), $i);
                        $path = $again;
                    }
                    if (! empty($chunk['requires_audio'])) {
                        $audio = Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=index', '-of', 'csv=p=0', $path]);
                        if (! $audio->successful() || trim($audio->output()) === '') throw new \RuntimeException('The ASMR clip has no native audio. Retry this segment before delivery; nothing was charged.');
                        // ASMR is its sound (2026-10-15): a clip that came back near-silent, or talking when nobody scripted words,
                        // is made once more at our cost; a second miss stops the take, uncharged, like the green backdrop.
                        if (! $sourceOnly && ($fault = $this->asmrFault($path, (string) ($chunk['dialogue'] ?? ''), $i)) !== null) {
                            if (! $this->owns($scene->refresh())) return;
                            \Illuminate\Support\Facades\Log::info('one-shot: ASMR sound wrong, clip made again', ['project_id' => $this->projectId, 'segment' => $i + 1, 'fault' => $fault]);
                            $again = $this->generateSegment($veo, $scene, $i, $chunk, $startFrame, $temps, $continuity);
                            if (! $this->owns($scene->refresh())) return;
                            if (($second = $this->asmrFault($again, (string) ($chunk['dialogue'] ?? ''), $i)) !== null) {
                                throw new \RuntimeException('Segment '.($i + 1).' came back '.$second.' twice, so it is not an ASMR clip. Nothing was charged. Try again, or add a short whispered line.');
                            }
                            $path = $again;
                        }
                    }
                    if (! $sourceOnly && ! empty($chunk['source_media'])) {
                        $media = $chunk['source_media'] + ['offset' => $chunk['source_offset'] ?? 0];
                        $path = app(\App\Services\Ugc\UgcComposition::class)->render($path, ['layout' => 'replace', 'assets' => [$media]], $storage, $temps);
                    }
                    $this->savePart($project, $scene, $storage, $i, $path);
                    if (isset($heard) && $heard !== null && isset($this->chunks[$i + 1])) $carry = self::carry($heard, (string) ($chunk['dialogue'] ?? ''), (string) ($this->chunks[$i + 1]['dialogue'] ?? ''));
                    // Follow the take, not the plan: how this clip really went, and the next clip rewritten to continue from it.
                    $mode = (string) config('services.ugc.follow_on', 'record');
                    if ($mode !== 'off' && ! empty($chunk['sheet']) && ! empty($this->chunks[$i + 1]['sheet']) && ! $sourceOnly) {
                        $next = $carry !== null ? self::carryOver($this->chunks[$i + 1], $carry) : $this->chunks[$i + 1];
                        $follow = app(\App\Services\Ugc\UgcFollowOn::class)->follow($chunk, $heard ?? null, $path, $clipVerdict, $next);
                        if ($follow !== null) {
                            $this->trace($project, $scene, $storage, $i, ['follow_on' => $follow + ['mode' => $mode]]);
                            if ($mode === 'apply' && $follow['close'] && $follow['valid']) $adapt = $follow['next'];
                        }
                    }
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
            // A POV hook's headlines, burned on the take: its words are the ad.
            if (! empty($this->chunks[0]['headlines'])) $finalPath = self::burnHeadlines($finalPath, $this->chunks[0]['headlines'], $temps);
            // The music bed, when asked for: made to the video's length and kept low under its own sound.
            if (! empty($this->chunks[0]['music'])) $finalPath = $this->musicBed($finalPath, (string) $this->chunks[0]['music'], $temps);
            // ASMR's quiet sounds raised to one steady level for phones, gently (no pumping of room hiss).
            if (! empty($this->chunks[0]['requires_audio'])) $finalPath = self::levelAsmr($finalPath, $temps);

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
            // Each section of the video, for remakes: where it starts and ends, and whether it carries straight on from the
            // one before (a remake of that one makes it again too).
            $sections = [];
            $at = 0.0;
            foreach ($segmentPaths as $i => $p) {
                $len = (float) (self::seconds($p) ?? 0);
                $chunk = $this->chunks[$i] ?? [];
                $label = trim((string) ($chunk['dialogue'] ?? '')) ?: trim((string) ($chunk['headlines'][0]['text'] ?? ''));
                $sections[] = ['i' => $i, 'start' => round($at, 2), 'end' => round($at + $len, 2), 'carried' => ! empty($fromTail[$i]), 'label' => mb_substr($label, 0, 80)];
                $at += $len;
            }
            $delivered = DB::transaction(function () use ($scene, $project, $asset, $duration, $credits, $sections) {
                $row = DB::table('scenes')->where('id', $this->sceneId)->lockForUpdate()->value('image_generation_settings_json');
                $token = (json_decode((string) $row, true) ?: [])['oneshot_token'] ?? null;
                if ($this->runToken !== null && $token !== null && $token !== $this->runToken) return false;
                $scene->refresh();
                // Held when the take started (DEDUCT-FALSE, owner 2026-10-15); a job queued before then is charged here instead.
                $held = (int) (($scene->image_generation_settings_json ?? [])['oneshot_held'] ?? 0);
                $before = (int) $scene->visual_asset_id;
                $scene->forceFill([
                    'visual_asset_id' => $asset->id,
                    'duration_seconds' => $duration ?: $scene->duration_seconds,
                    'status' => 'ready',
                    // The recipe and clips stay for SECTION_DAYS so a section can be remade (a delivered take never resumes:
                    // resume() refuses one). A remake keeps the video it replaced, for one step back.
                    'image_generation_settings_json' => array_diff_key(array_merge(
                        $scene->image_generation_settings_json ?? [],
                        ['in_progress' => false, 'last_error' => null, 'section_error' => null, 'sections' => $sections,
                            'sections_until' => now()->addDays(self::SECTION_DAYS)->toIso8601String()],
                        $this->remake !== [] && $before > 0 ? ['sections_previous' => ['asset_id' => $before, 'from' => (int) $this->remake['from'], 'to' => (int) $this->remake['to'],
                            'sections' => ($scene->image_generation_settings_json ?? [])['sections'] ?? []]] : [],
                    ), array_flip(['oneshot_held', 'section_remake'])),
                ])->save();

                $project->forceFill(['status' => 'ready_for_review'])->save();

                if ($held > 0) {
                    // Paid when it started: the charge line gets what the take really cost.
                    \App\Models\CreditLedgerEntry::query()->where('project_id', $this->projectId)->where('operation', $this->remake !== [] ? 'ugc_section' : 'ugc_oneshot')
                        ->latest('id')->limit(1)->update(['upstream_cost_usd' => self::realCost($this->projectId)]);
                    return true;
                }

                // Money moves only now, with the finished video stored.
                $charged = $credits->deduct($project->workspace_id, $this->credits, 'ugc_oneshot', [
                    'project_id' => $this->projectId, 'scene_id' => $this->sceneId,
                    'segments' => count($this->chunks),
                    // What the take really cost us (UGC-COST, 2026-10-15): every paid call recorded for this project, remakes,
                    // voice reads, lip-sync, the dressed picture and checks included, so the charge line shows its margin.
                    'upstream_cost_usd' => self::realCost($this->projectId),
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

            // Delivered: the saved clips stay for SECTION_DAYS, for section remakes (ugc:prune-oneshot-parts removes them).
            $this->learnFrom('delivered', (string) $asset->storage_url, null);
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
            $made = app(\App\Services\Ugc\UgcLook::class)->make($refs[0], $plate, (string) ($this->look['wardrobe'] ?? ''), (string) ($this->look['place'] ?? ''), $this->aspect);
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

    /** The presenter's face close-up, made once per take and kept with its saved parts ('none' when there is none). */
    private function faceClose(Project $project): ?string
    {
        $file = self::partsDir((int) $project->workspace_id, $this->sceneId).'/'.$this->recipeHash().'/face.txt';
        $uri = self::parts()->exists($file) ? (string) self::parts()->get($file) : '';
        if ($uri === '') {
            $uri = app(\App\Services\Ugc\UgcFaceCrop::class)->crop($this->referenceImages[0]) ?? 'none';
            self::parts()->put($file, $uri);
        }
        return $uri === 'none' ? null : $uri;
    }

    /** A skit speaker dressed for the ad (UgcLook), made once per speaker and kept with the take's parts; null if it failed. */
    private function dressedSpeaker(Project $project, Scene $scene, int $speaker, string $photo, ?string $plate, string $wardrobe, string $place): ?string
    {
        $file = self::partsDir((int) $project->workspace_id, $this->sceneId).'/'.$this->recipeHash().'/look-speaker-'.$speaker.'.txt';
        $uri = self::parts()->exists($file) ? (string) self::parts()->get($file) : '';
        if ($uri === '') {
            $made = app(\App\Services\Ugc\UgcLook::class)->make($photo, $plate, $wardrobe, $place, $this->aspect);
            if ($made === null) return null;
            $uri = $made['uri'];
            self::parts()->put($file, $uri);
            $looks = (array) (($scene->refresh()->image_generation_settings_json ?? [])['speaker_looks'] ?? []);
            $looks[(string) $speaker] = $made['score'];
            $scene->forceFill(['image_generation_settings_json' => array_merge($scene->image_generation_settings_json ?? [], ['speaker_looks' => $looks])])->save();
        }
        return $uri;
    }

    /** Staff pilots keep a trace of every step; customer takes do not. */
    private function tracing(array $chunk): bool
    {
        return ! empty($chunk['trace']) || $this->storyboard;
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
        // A section remake that failed leaves the delivered video as it was, and gives its credits back.
        if ($this->remake !== []) {
            Scene::query()->whereKey($this->sceneId)->get()->filter(fn (Scene $scene) => $this->owns($scene))->each(function (Scene $scene) use ($e): void {
                $scene->forceFill(['image_generation_settings_json' => array_merge($scene->image_generation_settings_json ?? [],
                    ['in_progress' => false, 'section_error' => mb_substr($e->getMessage(), 0, 300)])])->save();
                self::releaseHold((int) $scene->getKey(), 'ugc_section');
            });
            return;
        }
        Scene::query()->whereKey($this->sceneId)->get()
            ->filter(fn (Scene $scene) => $this->owns($scene) && ! ($scene->status === 'ready' && $scene->visual_asset_id))
            ->each(function (Scene $scene) use ($e): void {
            $scene->forceFill(['image_generation_settings_json' => array_merge(
                $scene->image_generation_settings_json ?? [],
                ['in_progress' => false, 'last_error' => mb_substr($e->getMessage(), 0, 300)],
            )])->save();
            // Nothing delivered, nothing paid: the credits held at the start go back.
            self::releaseHold((int) $scene->getKey());
            $this->learnFrom('failed', null, $e->getMessage());
        });

        $this->releasePassTake();
    }

    /** Queue the take's review (LEARN): what was asked, what the checks said, and the finished video when there is one. */
    private function learnFrom(string $outcome, ?string $videoUrl, ?string $error): void
    {
        rescue(function () use ($outcome, $videoUrl, $error): void {
            if (! config('services.ugc.learn', true)) return;
            $project = Project::query()->find($this->projectId);
            $settings = Scene::query()->whereKey($this->sceneId)->value('image_generation_settings_json') ?? [];
            $settings = is_array($settings) ? $settings : (json_decode((string) $settings, true) ?: []);
            \App\Jobs\ReviewUgcTakeJob::dispatch([
                'project_id' => $this->projectId, 'workspace_id' => $project?->workspace_id, 'outcome' => $outcome,
                'ugc_type' => (string) data_get($project?->visual_brief, 'ugc_type', ''), 'engine' => $this->engine,
                'brief' => trim(($project?->title ?? '').'. '.($project?->source_content_raw ?? '')),
                'clips' => array_map(fn ($c) => array_filter(['asked' => mb_substr((string) ($c['prompt'] ?? ''), 0, 700), 'line' => $c['dialogue'] ?? null,
                    'headlines' => $c['headlines'] ?? null]), $this->chunks),
                'checks' => array_filter(['face_scores' => $settings['face_scores'] ?? null, 'clip_reviews' => $settings['clip_reviews'] ?? null,
                    'dressed_picture_score' => $settings['look']['face_score'] ?? null]),
                'error' => $error, 'video_url' => $videoUrl,
            ]);
        }, null, false);
    }

    /**
     * Hold a take's credits as it starts (DEDUCT-FALSE, owner 2026-10-15: "hold credits at start"). Charged once, up
     * front, so a take delivered after the balance ran out (two takes at once) is still paid for; a take that does not
     * deliver gives them back (releaseHold). False when the balance cannot cover it.
     */
    public static function hold(Scene $scene, int $workspaceId, int $credits, string $operation = 'ugc_oneshot'): bool
    {
        if ($credits <= 0) return true;
        if (! app(CreditService::class)->deduct($workspaceId, $credits, $operation, ['project_id' => (int) $scene->project_id, 'scene_id' => (int) $scene->getKey(), 'metadata' => ['held' => true]])) return false;
        $settings = $scene->refresh()->image_generation_settings_json ?? [];
        $scene->forceFill(['image_generation_settings_json' => array_merge($settings, ['oneshot_held' => $credits])])->save();
        return true;
    }

    /** The credits held for a take that will not deliver go back, once (failure, a reaped run, a deleted take). */
    public static function releaseHold(int $sceneId, string $operation = 'ugc_oneshot'): void
    {
        DB::transaction(function () use ($sceneId, $operation): void {
            $row = DB::table('scenes')->where('id', $sceneId)->lockForUpdate()->first(['project_id', 'image_generation_settings_json']);
            $settings = $row ? (json_decode((string) $row->image_generation_settings_json, true) ?: []) : [];
            $held = (int) ($settings['oneshot_held'] ?? 0);
            if ($held <= 0) return;
            unset($settings['oneshot_held']);
            DB::table('scenes')->where('id', $sceneId)->update(['image_generation_settings_json' => json_encode($settings), 'updated_at' => now()]);
            $workspaceId = (int) DB::table('projects')->where('id', $row->project_id)->value('workspace_id');
            if ($workspaceId > 0) app(CreditService::class)->refund($workspaceId, $held, $operation);
        });
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
        $softened = null;
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
            } elseif ($this->faceRef !== null && $chunkRefs !== [] && empty($chunk['reference_images'])) {
                // The face close-up goes second, ahead of a second product angle; after a cut the last frame takes that place.
                $chunkRefs = array_slice(array_merge([$chunkRefs[0], $this->faceRef], array_slice($chunkRefs, 1)), 0, 3);
            }
            $prompt = $softened ?? (string) $chunk['prompt'];
            if (in_array($this->faceRef, $chunkRefs, true)) $prompt .= "\n\nOne reference image is a close-up of the presenter's face: in every frame their face matches it exactly, the same person.";
            $predictionId = $veo->start($prompt, (int) $chunk['seconds'], $startFrame, $this->engine, $chunkRefs, $this->seed, $this->resolution, [], $this->aspect);
            $scene->forceFill(['image_generation_settings_json' => array_merge(
                $scene->refresh()->image_generation_settings_json ?? [],
                // generation_started_at is the heartbeat the stuck-generation reaper reads.
                ['oneshot_segment' => $i + 1, 'oneshot_total' => count($this->chunks), 'oneshot_prediction_id' => $predictionId] + \App\Support\SceneRun::beat(),
            )])->save();

            try {
                // Omni makes a clip in about 40 seconds; five minutes is a hang, not a slow clip.
                $url = $veo->pollUntilDone($predictionId, $this->engine === 'omni' ? 300 : 600);
                if (($url === null || $url === '') && $attempt === 0) {
                    // A request the model never finished (take 306, 2026-10-15: one Omni clip "processing" for 30 minutes):
                    // cancelled and made once more, rather than failing the whole take.
                    $veo->cancel($predictionId);
                    \Illuminate\Support\Facades\Log::warning('one-shot: a clip hung, cancelled and made again', ['project_id' => $this->projectId, 'segment' => $i + 1, 'prediction_id' => $predictionId]);
                    continue;
                }
                if ($url === null || $url === '') $veo->cancel($predictionId);
                break;
            } catch (\RuntimeException $e) {
                // Declined by moderation: the same shot in neutral words, tried once (RD-01, from Pixelle-Video).
                if ($attempt === 0 && $softened === null && str_contains($e->getMessage(), 'declined this segment')
                    && ($softened = app(\App\Services\Ugc\UgcPromptSoften::class)->rewrite((string) $chunk['prompt'])) !== null) {
                    \Illuminate\Support\Facades\Log::info('one-shot: a declined clip was reworded and tried again', ['project_id' => $this->projectId, 'segment' => $i + 1]);
                    continue;
                }
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

    /**
     * The speaker's read of an Omni clip's line, as a local WAV with its length; null when the clip is not voiced this
     * way, or when the read failed (the clip then keeps Omni's own voice: a worse take, not a lost one).
     *
     * @return array{path: string, seconds: float}|null
     */
    private function voiceRead(array $chunk, int $i, Project $project, array &$temps): ?array
    {
        $line = trim((string) ($chunk['dialogue'] ?? ''));
        $voice = $chunk['voice'] ?? null;
        if ($this->engine !== 'omni' || ! config('services.ugc.omni_lipsync') || $line === '' || empty($voice['id'])) return null;
        try {
            $tts = app(\App\Services\Generation\TTS\TTSAdapter::class)->synthesize($line, 'en', (string) $voice['id'], (float) ($voice['speed'] ?? 1.0), [
                'voice_prompt' => (string) ($voice['direction'] ?? ''), 'usage_context' => ['workspace_id' => (int) $project->workspace_id, 'project_id' => $project->id, 'purpose' => 'ugc_lipsync_read'],
            ]);
            $url = (string) ($tts['audio_url'] ?? '');
            $bytes = str_starts_with($url, 'http') ? @file_get_contents($url) : app(StorageService::class)->get($url);
            if (! is_string($bytes) || $bytes === '') throw new \RuntimeException('the read could not be fetched');
            $raw = tempnam(sys_get_temp_dir(), 'oneshot-read-');
            $wav = $raw.'.wav';
            array_push($temps, $raw, $wav);
            file_put_contents($raw, $bytes);
            // Sync takes WAV; a short tail of silence keeps the last word from being clipped at the cut, and the ad's final
            // clip keeps a second after its last word, so it closes rather than stops (owner, 2026-10-15).
            $run = Process::timeout(60)->run(['ffmpeg', '-y', '-i', $raw, '-af', 'apad=pad_dur='.(! empty($chunk['final']) ? '1.0' : '0.25'), '-ar', '48000', '-ac', '1', $wav]);
            $seconds = $run->successful() ? self::seconds($wav) : null;
            if ($seconds === null) throw new \RuntimeException('the read could not be converted');
            return ['path' => $wav, 'seconds' => $seconds];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('one-shot: line not read, Omni keeps its own voice', ['project_id' => $this->projectId, 'segment' => $i + 1, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }
    }

    /** The clip with the speaker's read synced onto its mouth; the clip as made when the sync fails. */
    private function lipsync(string $clip, array $read, int $i, array &$temps): string
    {
        try {
            // A clip longer than the read is cut to it first, so the presenter does not stand silent at the end.
            $made = self::seconds($clip);
            if ($made !== null && $made > $read['seconds'] + 0.5) {
                $cut = tempnam(sys_get_temp_dir(), 'oneshot-cut-').'.mp4';
                $temps[] = $cut;
                $run = Process::timeout(120)->run(['ffmpeg', '-y', '-i', $clip, '-t', sprintf('%.2f', $read['seconds'] + 0.2), '-c:v', 'libx264', '-preset', 'fast', '-crf', '18', '-c:a', 'aac', $cut]);
                if ($run->successful() && filesize($cut)) $clip = $cut;
            }
            $synced = app(\App\Services\Generation\Video\ReplicateLipsyncAdapter::class)->sync($clip, $read['path']);
            $temps[] = $synced;
            return $synced;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('one-shot: lip-sync failed, Omni keeps its own voice', ['project_id' => $this->projectId, 'segment' => $i + 1, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return $clip;
        }
    }

    /** A music track made for the take and mixed quietly under its sound; the video as it was when that fails. */
    private function musicBed(string $path, string $prompt, array &$temps): string
    {
        try {
            $seconds = (int) ceil(self::seconds($path) ?? 8);
            $music = app(\App\Services\Generation\Music\ReplicateMusicAdapter::class)->generate($prompt, min(30, max(3, $seconds)));
            $track = tempnam(sys_get_temp_dir(), 'oneshot-music-').'.mp3';
            $out = tempnam(sys_get_temp_dir(), 'oneshot-music-').'.mp4';
            array_push($temps, $track, $out);
            file_put_contents($track, (string) file_get_contents((string) $music['audio_url']));
            $hasSound = trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=index', '-of', 'csv=p=0', $path])->output()) !== '';
            $bed = sprintf('[1:a]volume=0.22,afade=t=in:d=0.4,afade=t=out:st=%.2f:d=1[m]', max(0, $seconds - 1));
            $graph = $hasSound ? $bed.';[0:a][m]amix=inputs=2:duration=first:normalize=0[a]' : $bed.';[m]atrim=0:'.$seconds.'[a]';
            $run = Process::timeout(300)->run(['ffmpeg', '-y', '-i', $path, '-i', $track, '-filter_complex', $graph, '-map', '0:v', '-map', '[a]', '-c:v', 'copy', '-c:a', 'aac', '-movflags', '+faststart', $out]);
            if (! $run->successful() || ! filesize($out)) throw new \RuntimeException('the mix failed');
            return $out;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('one-shot: music bed not added', ['project_id' => $this->projectId, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return $path;
        }
    }

    /** The headlines drawn over the video at their times, sound copied as it is. */
    private static function burnHeadlines(string $path, array $cues, array &$temps): string
    {
        $size = trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0:s=x', $path])->output());
        [$w, $h] = array_map('intval', explode('x', $size) + [1 => 0]);
        $duration = self::seconds($path);
        if ($w <= 0 || $h <= 0 || $duration === null) throw new \RuntimeException('The headline could not be added: the video could not be read. Nothing was charged; try again.');
        $ass = tempnam(sys_get_temp_dir(), 'oneshot-headline-').'.ass';
        $out = tempnam(sys_get_temp_dir(), 'oneshot-headline-').'.mp4';
        array_push($temps, $ass, $out);
        file_put_contents($ass, \App\Services\Ugc\UgcHeadline::assTimed($cues, $w, $h, $duration));
        $run = Process::timeout(600)->run(['ffmpeg', '-y', '-i', $path, '-vf', 'subtitles='.$ass, '-c:v', 'libx264', '-preset', 'fast', '-crf', '19', '-c:a', 'copy', '-movflags', '+faststart', $out]);
        if (! $run->successful() || ! filesize($out)) throw new \RuntimeException('The headline could not be added to the video. Nothing was charged; try again.');
        return $out;
    }

    /** The recorded cost of every paid call made for this take's project, in dollars; null when nothing was recorded. */
    public static function realCost(int $projectId): ?float
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('api_usage_events')) return null;
        $usd = DB::table('api_usage_events')->where('project_id', $projectId)->sum('estimated_cost_usd');
        return (float) $usd > 0 ? round((float) $usd, 6) : null;
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
    /** What is wrong with an ASMR clip's sound, or null: near-silent, or words nobody scripted (a whisper that is scripted is fine). */
    private function asmrFault(string $path, string $dialogue, int $i): ?string
    {
        $level = Process::timeout(60)->run(['ffmpeg', '-v', 'info', '-i', $path, '-vn', '-af', 'volumedetect', '-f', 'null', '-']);
        if (! preg_match('/mean_volume:\s*(-?[\d.]+) dB/', $level->errorOutput().$level->output(), $m)) return 'with no sound';
        if ((float) $m[1] < -55) return 'nearly silent';
        if (self::tokens($dialogue) !== []) return null;
        try {
            $t = app(\App\Services\Media\MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps($path, 'video/mp4');
        } catch (\Throwable $e) {
            return null; // a transcript is a check, not the truth: an outage keeps the clip
        }
        if (($t['provider_key'] ?? '') === 'local_fallback') return null;
        // A few words can be a transcriber hearing texture as speech ("Thank you."); four or more is someone talking.
        $words = array_filter($t['words'] ?? [], fn ($w) => self::tokens((string) ($w['text'] ?? $w['word'] ?? '')) !== []);
        return count($words) >= 4 ? 'with someone talking' : null;
    }

    /** One steady level for ASMR (-20 LUFS, gentle range), video untouched. Any failure keeps the take as made. */
    private static function levelAsmr(string $path, array &$temps): string
    {
        $out = tempnam(sys_get_temp_dir(), 'asmr-level-').'.mp4';
        $temps[] = $out;
        $run = Process::timeout(300)->run(['ffmpeg', '-y', '-i', $path, '-c:v', 'copy', '-af', 'loudnorm=I=-20:TP=-2:LRA=11', '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', $out]);
        return $run->successful() && filesize($out) ? $out : $path;
    }

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
        if ($this->aspect !== '9:16') $recipe[] = $this->aspect;
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
            'seed' => $this->seed, 'resolution' => $this->resolution, 'demoStorageUrl' => $this->demoStorageUrl, 'checkFace' => $this->checkFace, 'composition' => $this->composition, 'storyboard' => $this->storyboard, 'look' => $this->look, 'aspect' => $this->aspect,
        ]));
        $scene->forceFill(['image_generation_settings_json' => array_merge($scene->refresh()->image_generation_settings_json ?? [],
            ['oneshot_recipe' => $recipe, 'oneshot_parts_hash' => $hash, 'oneshot_parts' => []])])->save();
        return [];
    }

    /** A skit clip that shows the other speaker, on the take's record (RD-02). */
    private function noteMixup(Scene $scene, int $i, int $speaker, int $shown, int $own, int $theirs): void
    {
        $settings = $scene->refresh()->image_generation_settings_json ?? [];
        $mixups = (array) ($settings['speaker_mixups'] ?? []);
        $mixups[(string) ($i + 1)] = ['speaker' => $speaker, 'looks_like' => $shown, 'own' => $own, 'theirs' => $theirs];
        $scene->forceFill(['image_generation_settings_json' => array_merge($settings, ['speaker_mixups' => $mixups])])->save();
        \Illuminate\Support\Facades\Log::warning('one-shot: a skit clip shows the other speaker', ['project_id' => $this->projectId, 'segment' => $i + 1, 'speaker' => $speaker, 'looks_like' => $shown]);
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
        $keys = array_flip(['oneshot_recipe', 'oneshot_parts', 'oneshot_parts_hash', 'sections', 'sections_until', 'sections_previous']);
        if (array_intersect_key($settings, $keys)) {
            $scene->forceFill(['image_generation_settings_json' => array_diff_key($settings, $keys)])->save();
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
            // A failed take gave its credits back: they are held again as it resumes, or it does not resume.
            if ((int) ($settings['oneshot_held'] ?? 0) <= 0) {
                if (! self::hold($scene, (int) $project->workspace_id, (int) $recipe['credits'])) return 'short';
                $settings = $scene->refresh()->image_generation_settings_json ?? [];
            }
            $token = (string) Str::uuid();
            $scene->forceFill(['image_generation_settings_json' => array_merge($settings, [
                'last_error' => null, 'in_progress' => true, 'generation_started_at' => now()->toIso8601String(), 'oneshot_token' => $token,
            ])])->save();
            $project->forceFill(['status' => 'generating'])->save();
            self::dispatch(
                (int) $recipe['projectId'], (int) $recipe['sceneId'], $recipe['chunks'], (int) $recipe['credits'], (string) $recipe['engine'],
                $recipe['referenceImages'] ?? [], $recipe['initialStartFrame'] ?? null, $recipe['seed'] ?? null,
                (string) ($recipe['resolution'] ?? '720p'), $recipe['demoStorageUrl'] ?? null, $token, (bool) ($recipe['checkFace'] ?? false), $recipe['composition'] ?? [], (bool) ($recipe['storyboard'] ?? false), (array) ($recipe['look'] ?? []), (string) ($recipe['aspect'] ?? '9:16'),
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

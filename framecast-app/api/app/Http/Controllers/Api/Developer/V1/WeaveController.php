<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Http\Controllers\Api\V1\Create\CreateController;
use App\Services\Create\ConversationService;
use App\Services\Create\OutputSettings;
use App\Services\Create\VariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Weave (Create) through the developer API and MCP (L3, 2026-10-09; owner: always confirm the price, planning starts
 * at once, seven tools). Each step forwards to the app's own Create controller or services, so an assistant goes
 * through exactly what the app does: brief, plan, price, approve, build, change, share. The conversation's version
 * is read fresh for every step; an assistant never handles it. Scores, rounds and the reviewer never appear.
 */
class WeaveController extends DeveloperController
{
    public function __construct(private ConversationService $conversations) {}

    /** The newest Weave videos, with where each one stands. */
    public function index(Request $request): JsonResponse
    {
        $input = $this->validated($request, ['limit' => 'sometimes|integer|min:1|max:50']);
        $rows = DB::table('create_conversations')->where('workspace_id', $request->user()->workspace_id)->whereNull('archived_at')
            ->orderByDesc('updated_at')->limit($input['limit'] ?? 10)->get(['id', 'title', 'updated_at', 'head_revision_id']);
        return response()->json(['data' => ['videos' => $rows->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'updated_at' => $c->updated_at,
            'state' => $this->status($request, $c->id)['state']])->values()]]);
    }

    /** A brief, its settings, files and a reference link: the conversation is made and planning starts at once. */
    public function store(Request $request): JsonResponse
    {
        $input = $this->validated($request, [
            'brief' => 'required|string|min:3|max:10000',
            'format' => 'sometimes|nullable|string|max:40', 'aspect_ratio' => 'sometimes|in:9:16,16:9,1:1,4:5',
            'duration_seconds' => 'sometimes|integer|min:5|max:30', 'voice' => 'sometimes|nullable|string|max:40',
            'style_pack' => 'sometimes|nullable|string|max:40', 'effort' => 'sometimes|in:quick,standard,thorough',
            'captions' => 'sometimes|boolean', 'reference_url' => 'sometimes|nullable|url|max:500',
            'asset_ids' => 'sometimes|array|max:10', 'asset_ids.*' => 'integer|min:1',
            'idempotency_key' => 'sometimes|string|max:100',
        ]);
        $user = $request->user();
        $key = $input['idempotency_key'] ?? (string) Str::uuid();
        // The same key replays: the conversation it made is returned, not a second one.
        $existing = DB::table('create_messages')->join('create_conversations', 'create_conversations.id', '=', 'create_messages.conversation_id')
            ->where('create_conversations.workspace_id', $user->workspace_id)->where('create_messages.idempotency_key', 'weave:'.$key)->value('create_conversations.id');
        if ($existing) return response()->json(['data' => $this->status($request, $existing)]);

        $settings = array_filter([
            'output_kind' => 'video', 'aspect_ratio' => $input['aspect_ratio'] ?? null, 'duration_seconds' => $input['duration_seconds'] ?? null,
            'duration_chosen' => isset($input['duration_seconds']) ? true : null, 'format' => $input['format'] ?? null, 'voice' => $input['voice'] ?? null,
            'style_pack' => $input['style_pack'] ?? null, 'effort' => $input['effort'] ?? null, 'no_captions' => ($input['captions'] ?? true) ? null : true,
        ], fn ($v) => $v !== null);
        $c = $this->conversations->create($user, OutputSettings::normalize($settings));
        foreach (array_unique($input['asset_ids'] ?? []) as $assetId) $this->conversations->attach($user, $c->id, (int) $assetId, 'auto', $this->version($request, $c->id));
        if (! empty($input['reference_url'])) {
            $this->forward($request, 'reference', ['url' => $input['reference_url'], 'idempotency_key' => 'weave-ref:'.$key, 'expected_version' => $this->version($request, $c->id)], $c->id);
        }
        $this->conversations->message($user, $c->id, ['content' => $input['brief'], 'idempotency_key' => 'weave:'.$key, 'expected_version' => $this->version($request, $c->id)]);
        $this->startPlanning($request, $c->id);
        return response()->json(['data' => $this->status($request, $c->id)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->status($request, $id)]);
    }

    /** An answer to the planner's question, a change to the plan, or a change to the finished video: it re-plans. */
    public function message(Request $request, string $id): JsonResponse
    {
        $input = $this->validated($request, ['message' => 'required|string|min:1|max:10000', 'idempotency_key' => 'sometimes|string|max:100']);
        $key = $input['idempotency_key'] ?? (string) Str::uuid();
        $this->conversations->message($request->user(), $id, ['content' => $input['message'], 'idempotency_key' => 'weave:'.$key, 'expected_version' => $this->version($request, $id)]);
        $this->startPlanning($request, $id);
        return response()->json(['data' => $this->status($request, $id)], 201);
    }

    /**
     * The price of the next step, never started here: the plan's video, or the look to approve first. A look the
     * user approved is recorded first (look_token, from status).
     */
    public function quote(Request $request, string $id): JsonResponse
    {
        $input = $this->validated($request, ['look_token' => 'sometimes|string|regex:/^[a-f0-9]{64}$/']);
        $user = $request->user();
        if (isset($input['look_token'])) {
            $plan = DB::table('create_plans')->where('conversation_id', $id)->orderByDesc('created_at')->firstOrFail();
            $present = app(\App\Services\Create\PlanService::class)->present($plan, $this->conversations->conversation($user, $id));
            $step = ($present['character_preview']['token'] ?? null) === $input['look_token'] ? 'character'
                : (($present['storyboard_preview']['token'] ?? null) === $input['look_token'] ? 'storyboard' : null);
            if (! $step) return $this->fail('look_changed', 'That look is no longer the one waiting for approval. Check the status again.', 409);
            app(\App\Services\Create\PlanService::class)->select($user, $id, $plan->id, $this->version($request, $id), [$step === 'character' ? 'character_approval' : 'storyboard_approval' => $input['look_token']]);
        }
        $q = app(VariantService::class)->quote($user, $id, $this->version($request, $id), 1);
        $p = $q->payload_json;
        return response()->json(['data' => [
            'quote_id' => $q->id, 'credits_max' => (int) $q->credits_max, 'credits_estimate' => isset($p['estimate']) ? (int) $p['estimate'] : null,
            'makes' => match ($p['build_stage'] ?? null) { 'character' => 'the look of the people in the video, to approve before the video', 'storyboard' => 'a storyboard, to approve before the video', default => 'the video' },
            'paid_media' => array_map(fn ($m) => ['what' => $m['description'] ?? $m['kind'], 'credits' => (int) ($m['credits'] ?? 0)], $p['plan_media'] ?? []),
            'credits_available' => $available = (int) ($this->conversations->creditAvailability($user)['available'] ?? 0),
            'can_afford' => $available >= (int) $q->credits_max,
            // Short of credits: the assistant offers a cheaper version or the top-up page, never a failed build.
            'if_short' => $available >= (int) $q->credits_max ? null : [
                'cheaper' => ['effort quick instead of standard or thorough', 'a shorter video', 'fewer paid shots (ask Weave with weave_reply to drop AI clips or presenter takes)'],
                'top_up_url' => rtrim((string) config('app.url'), '/').'/settings?section=usage',
            ],
            'expires_at' => $q->expires_at,
            'note' => 'Nothing is charged until this quote is approved. The amount is a maximum: only what is used is charged. Approving also agrees to send the brief and approved media to our AI providers.',
        ]]);
    }

    /** The user's go for a quote they were shown: the build starts. */
    public function run(Request $request, string $id): JsonResponse
    {
        $input = $this->validated($request, ['quote_id' => 'required|string|max:32', 'idempotency_key' => 'sometimes|string|max:100']);
        $run = app(VariantService::class)->approve($request->user(), $id, $input['quote_id'], 'weave-run:'.($input['idempotency_key'] ?? $input['quote_id']), true);
        return response()->json(['data' => ['run_id' => $run->id] + $this->status($request, $id)], 202);
    }

    /** A link anyone can watch: the finished version is saved to the library first, as the app does. */
    public function share(Request $request, string $id): JsonResponse
    {
        $input = $this->validated($request, ['version' => 'sometimes|integer|min:1', 'enabled' => 'sometimes|boolean']);
        $c = $this->conversations->conversation($request->user(), $id);
        $revision = DB::table('composition_revisions')->where('conversation_id', $id)
            ->when(isset($input['version']), fn ($q) => $q->where('number', $input['version']), fn ($q) => $q->where('id', $c->head_revision_id))->first();
        if (! $revision) return $this->fail('not_ready', 'There is no finished version to share yet.', 409);
        $user = $request->user();
        if (! ($input['enabled'] ?? true)) {
            return response()->json(['data' => app(\App\Services\Create\DeliveryService::class)->deliver($user, $id, $revision->id, ['action' => 'unshare', 'expected_version' => $this->version($request, $id)])]);
        }
        if (! $revision->output_asset_id) app(\App\Services\Create\CompositionOutputService::class)->register($user, $id, $revision->id, $this->version($request, $id));
        return response()->json(['data' => app(\App\Services\Create\DeliveryService::class)->deliver($user, $id, $revision->id,
            ['action' => 'share', 'confirmed' => true, 'allow_older' => true, 'expected_version' => $this->version($request, $id)])]);
    }

    /** Where the video stands, in one state an assistant can act on. */
    private function status(Request $request, string $id): array
    {
        $user = $request->user();
        $c = $this->conversations->conversation($user, $id);
        $run = DB::table('composition_runs')->where('conversation_id', $id)->orderByDesc('created_at')->first(['id', 'status', 'stage', 'error', 'input_json', 'created_at']);
        $plan = DB::table('create_plans')->where('conversation_id', $id)->orderByDesc('created_at')->first();
        $revision = $c->head_revision_id ? DB::table('composition_revisions')->where('id', $c->head_revision_id)->first(['id', 'number', 'summary', 'created_at', 'artifact_path']) : null;
        $lastMessage = DB::table('create_messages')->where('conversation_id', $id)->orderByDesc('sequence')->first(['role', 'content', 'idempotency_key', 'created_at']);
        $job = config('create.durable_planning') ? app(\App\Services\Create\PlanningJobService::class)->latest($id, null) : \Illuminate\Support\Facades\Cache::get('create:plan-job:'.$id);
        $base = ['id' => $id, 'title' => $c->title, 'app_url' => rtrim((string) config('app.frontend_url'), '/').'/create/'.$id];
        $video = $revision ? ['version' => (int) $revision->number, 'summary' => $revision->summary,
            'preview_url' => $revision->artifact_path ? \Illuminate\Support\Facades\URL::temporarySignedRoute('media.create.version', now()->addMinutes(45), ['revisionId' => $revision->id]) : null] : null;

        if ($run && in_array($run->status, ['queued', 'running', 'cancel_requested'], true)) {
            return $base + ['state' => 'building', 'stage' => $run->stage ?: 'Starting', 'next' => 'Check again in a minute or two; a video usually takes a few minutes.', 'video' => $video];
        }
        if (in_array($job['state'] ?? null, ['queued', 'running'], true)) {
            return $base + ['state' => 'planning', 'next' => 'Planning takes a minute or two. Check again shortly.', 'video' => $video];
        }
        if (in_array($job['state'] ?? null, ['failed', 'needs_attention'], true) && (! $plan || strtotime((string) $plan->created_at) < strtotime((string) ($job['started_at'] ?? $job['queued_at'] ?? 'now')))) {
            return $base + ['state' => 'needs_attention', 'problem' => $job['error'] ?? 'Planning did not finish.', 'next' => 'Send a message to try again, or change the brief.', 'video' => $video];
        }
        // A question from the planner is the newest thing in the conversation: it waits for the user's answer.
        if ($lastMessage && $lastMessage->role === 'assistant' && preg_match('/^(clarify|clarify-change|reference-match|role|study|materials|file):/', (string) $lastMessage->idempotency_key)
            && (! $plan || strtotime((string) $lastMessage->created_at) >= strtotime((string) $plan->created_at))) {
            return $base + ['state' => 'question', 'question' => $lastMessage->content, 'next' => 'Ask the user, then send their answer with weave_reply.', 'video' => $video];
        }
        if ($run && $run->status === 'needs_attention' && (! $plan || strtotime((string) $plan->created_at) <= strtotime((string) $run->created_at))) {
            return $base + ['state' => 'needs_attention', 'problem' => $run->stage ?: ($run->error ?: 'The build stopped.'), 'next' => 'Open the video in the app to continue, or send a change with weave_reply.', 'video' => $video];
        }
        if ($plan && $plan->status === 'proposed' && ! \App\Services\Create\PlanService::built($plan->id)) {
            $present = app(\App\Services\Create\PlanService::class)->present($plan, $c);
            $look = collect([$present['character_preview'] ?? null, $present['storyboard_preview'] ?? null])->first(fn ($l) => $l && ! empty($l['images']) && ! $l['approved']);
            if ($look) {
                return $base + ['state' => 'look_ready', 'look_token' => $look['token'], 'images' => array_values(array_filter(array_map(fn ($i) => is_array($i) && ($i['preview_url'] ?? null) ? array_filter(['url' => $i['preview_url'], 'label' => $i['label'] ?? $i['name'] ?? null]) : null, (array) $look['images']))),
                    'next' => 'Show the user these images. If they like them, call weave_approve with look_token to see the price of the video; otherwise send changes with weave_reply.', 'video' => $video];
            }
            return $base + ['state' => 'plan_ready', 'plan' => $this->planSummary(json_decode((string) $plan->plan_json, true) ?: []),
                'next' => 'Show the user the plan. To make it, call weave_approve for the price, show it to the user, and approve only after they agree. To change it, use weave_reply.', 'video' => $video];
        }
        if ($video) {
            return $base + ['state' => 'ready', 'video' => $video, 'next' => 'Share the preview link. Changes go through weave_reply; a share link through weave_share.'];
        }
        return $base + ['state' => 'idle', 'next' => 'Send the brief with weave_reply to start planning.'];
    }

    /** What the plan will make, in plain words: the idea, the other directions, the scenes and the script. */
    private function planSummary(array $p): array
    {
        $narration = is_array($p['narration'] ?? null) ? array_values(array_filter($p['narration'], 'is_string')) : (is_string($p['narration'] ?? null) ? [$p['narration']] : []);
        return array_filter([
            'summary' => $p['summary'] ?? null,
            'idea' => isset($p['concept']['name']) ? $p['concept']['name'].': '.($p['concept']['idea'] ?? '') : null,
            'other_directions' => array_values(array_map(fn ($a) => ($a['name'] ?? '').': '.($a['idea'] ?? ''), array_slice((array) ($p['concept']['alternatives'] ?? []), 0, 4))),
            'scenes' => array_values(array_map(fn ($s) => array_filter(['from' => $s['start'] ?? null, 'to' => $s['end'] ?? null, 'what' => $s['label'] ?? null, 'idea' => $s['idea'] ?? null], fn ($v) => $v !== null), (array) ($p['scenes'] ?? []))),
            'script' => $narration,
            'voice' => is_string($p['voice'] ?? null) ? $p['voice'] : null,
            'assumptions' => array_values((array) ($p['assumptions'] ?? [])),
            // The first plan asks whether to keep its length, screen and voice, and how a name is said: ask the user.
            'questions' => array_values((array) ($p['checks'] ?? [])),
        ], fn ($v) => $v !== null && $v !== []);
    }

    private function version(Request $request, string $id): int
    {
        return (int) $this->conversations->conversation($request->user(), $id)->version;
    }

    /** Planning in the background, as the app starts it after every brief. */
    private function startPlanning(Request $request, string $id): void
    {
        $this->forward($request, 'plan', ['expected_version' => $this->version($request, $id), 'idempotency_key' => 'weave-plan:'.Str::uuid(), 'async' => true], $id);
    }

    /** Calls the app's own Create controller for a step, as the same user. */
    private function forward(Request $request, string $method, array $data, string ...$args): mixed
    {
        $sub = Request::create($request->getPathInfo(), 'POST', $data);
        $sub->setUserResolver(fn () => $request->user());
        return app(CreateController::class)->{$method}($sub, ...$args);
    }
}

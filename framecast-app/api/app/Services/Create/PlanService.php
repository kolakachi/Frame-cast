<?php
namespace App\Services\Create;

use App\Models\{Asset, User};
use App\Services\Create\Planning\{AnthropicPlanner, OfflinePlanner, Planner, ReplicatePlanner};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The plan turn. A plan is free to the user: WyvStudio pays for the single
 * planning call, bounded by a daily limit. The model proposes; this service
 * decides what is allowed (only supplied files, only known tools, prices from
 * the catalogue) and the quote binds to the plan the user approved.
 */
class PlanService
{
    public function __construct(private ConversationService $conversations) {}

    public function planner(string $task = 'edit'): Planner
    {
        $choice = config('create.mode') === 'fixture' ? 'offline' : (string) config('create.planner', 'offline');
        $model = $task === 'creative' ? (string) config('create.planner_model_creative', 'claude-opus-5-5') : (string) config('create.planner_model', 'claude-opus-5-5');
        return match ($choice) {
            'replicate' => new ReplicatePlanner((string) config('create.planner_model', 'anthropic/claude-sonnet-5'), (string) config('services.replicate.api_token')),
            'anthropic' => new AnthropicPlanner($model, (string) config('services.anthropic.key')),
            default => new OfflinePlanner,
        };
    }

    /**
     * Which planning job this is. Creative: the first plan of a creation, or a follow-up long enough to rewrite the
     * brief (40 words or more). Edit: a short follow-up to an existing plan (a correction, a tweak, a timing fix).
     */
    public static function plannerTask(object $c): string
    {
        if (! DB::table('create_plans')->where('conversation_id', $c->id)->exists()) return 'creative';
        $last = (string) DB::table('create_messages')->where('conversation_id', $c->id)->where('role', 'user')->orderByDesc('sequence')->value('content');
        return count(preg_split('/\s+/u', trim($last), -1, PREG_SPLIT_NO_EMPTY)) >= 40 ? 'creative' : 'edit';
    }

    public function propose(User $user, string $id, int $version, string $key, bool $skipQuestions = false): array
    {
        $this->conversations->authorize($user, true);
        $c = $this->conversations->conversation($user, $id);
        $hash = hash('sha256', $id.'|'.$version);
        $old = DB::table('create_plans')->where('conversation_id', $id)->where('idempotency_key', $key)->first();
        if ($old) {
            abort_unless(hash_equals($old->request_hash, $hash), 409, 'This request key already belongs to a different plan.');
            return $this->present($old, $c);
        }
        abort_if($c->archived_at, 409, 'Restore this conversation before planning.');
        abort_unless((int) $c->version === $version, 409, 'Conversation changed. Refresh before planning.');
        $briefs = DB::table('create_messages')->where('conversation_id', $id)->where('role', 'user')->orderBy('sequence')->get(['content', 'sequence']);
        abort_if($briefs->isEmpty(), 422, 'Add a brief first.');
        $today = DB::table('create_plans')->join('create_conversations', 'create_conversations.id', '=', 'create_plans.conversation_id')
            ->where('create_conversations.workspace_id', $user->workspace_id)->where('create_plans.created_at', '>=', now()->startOfDay())->count();
        abort_if(! PilotPolicy::unlimited() && $today >= (int) config('create.plan_daily_limit', 40), 429, 'Today\'s planning limit is reached. Plans reset at midnight.');

        // What planning does is recorded step by step: shown live while it runs, then saved with the plan.
        $activity = PlanActivity::begin($id);
        try {
        // Files attached without a role get one now, before anything reads them: from the brief and from looking at
        // each file. A file whose role is unclear is asked about, one at a time, and the reply decides it.
        $lastTwo = DB::table('create_messages')->where('conversation_id', $id)->orderByDesc('sequence')->limit(2)->get(['role', 'content', 'idempotency_key']);
        $roleAnswer = ($lastTwo[0]->role ?? null) === 'user' && str_starts_with((string) ($lastTwo[1]->idempotency_key ?? ''), AttachmentRoles::ASK_PREFIX)
            ? [(int) explode(':', (string) $lastTwo[1]->idempotency_key)[1], (string) $lastTwo[0]->content] : null;
        $sorted = app(AttachmentRoles::class)->resolve($user, $c, $roleAnswer, $skipQuestions);
        if ($sorted['unsure']) {
            $assetId = (int) $sorted['unsure'][0];
            $question = AttachmentRoles::question((string) (Asset::where('workspace_id', $user->workspace_id)->find($assetId)?->title ?? 'this file'));
            $next = (int) $c->version + 1;
            DB::table('create_messages')->insert(['id' => (string) Str::uuid(), 'conversation_id' => $id, 'role' => 'assistant', 'content' => $question,
                'idempotency_key' => AttachmentRoles::ASK_PREFIX.$assetId.':'.$key, 'request_hash' => hash('sha256', $question), 'sequence' => $next, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('create_conversations')->where('id', $id)->update(['version' => $next, 'updated_at' => now()]);
            $activity->abandon();
            return ['needs_answer' => 'role', 'question' => $question, 'asset_id' => $assetId];
        }
        // Every file's role, every time, so the plan's activity always says what is used and what is followed.
        $placed = DB::table('create_attachments')->join('assets', 'assets.id', '=', 'create_attachments.asset_id')->where('create_attachments.conversation_id', $id)
            ->whereIn('create_attachments.purpose', ['source', 'reference'])->orderBy('create_attachments.id')->get(['assets.title', 'create_attachments.purpose']);
        if ($placed->isNotEmpty()) {
            $activity->step('Sorted your files');
            foreach ($placed as $f) $activity->item(($f->title ?: 'A file').($f->purpose === 'source' ? ' goes in the video' : ' is the reference to follow'));
        }
        // With a reference video, how closely to follow it is decided before planning: from Details, from the
        // brief, or by asking (a copy and an inspiration plan differently, so the planner does not guess).
        $settings = json_decode($c->settings_json, true) ?: [];
        $hasReferenceVideo = DB::table('create_attachments')->join('assets', 'assets.id', '=', 'create_attachments.asset_id')
            ->where('create_attachments.conversation_id', $id)->where('create_attachments.purpose', 'reference')->where('assets.asset_type', 'video')->exists();
        if ($hasReferenceVideo && ($settings['output_kind'] ?? 'video') === 'video' && empty($settings['reference_match'])) {
            $match = ReferenceMatch::infer($briefs->pluck('content')->implode("\n"));
            // An answer in the user's own words is read for its meaning, and skipping takes the usual choice: the
            // question is never asked twice.
            $recent = DB::table('create_messages')->where('conversation_id', $id)->orderByDesc('sequence')->limit(2)->get();
            $answered = ($recent[0]->role ?? null) === 'user' && ($recent[1]->content ?? null) === ReferenceMatch::QUESTION;
            if (! $match && ($answered || $skipQuestions)) {
                $match = ($answered ? (ReferenceMatch::answer((string) $recent[0]->content) ?? app(Planning\Clarifier::class)->referenceMatch((string) $recent[0]->content)) : null) ?? 'similar';
                $activity->step('Read your answer');
                $activity->item('Following the reference: '.['exact' => 'exactly, moment for moment', 'similar' => 'its format, look and pacing, with your own story', 'inspired' => 'just the idea'][$match].'. Change this in Details.');
            }
            if (! $match) {
                $last = DB::table('create_messages')->where('conversation_id', $id)->orderByDesc('sequence')->first();
                if (! $last || $last->content !== ReferenceMatch::QUESTION) {
                    $next = (int) $c->version + 1;
                    DB::table('create_messages')->insert(['id' => (string) Str::uuid(), 'conversation_id' => $id, 'role' => 'assistant', 'content' => ReferenceMatch::QUESTION,
                        'idempotency_key' => 'reference-match:'.$key, 'request_hash' => hash('sha256', ReferenceMatch::QUESTION), 'sequence' => $next, 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('create_conversations')->where('id', $id)->update(['version' => $next, 'updated_at' => now()]);
                }
                $activity->abandon();
                return ['needs_answer' => 'reference_match', 'question' => ReferenceMatch::QUESTION];
            }
            DB::table('create_conversations')->where('id', $id)->update(['settings_json' => json_encode(OutputSettings::normalize([...$settings, 'reference_match' => $match]))]);
            $c = $this->conversations->conversation($user, $id);
        }
        // Every attached reference video is studied before planning (a link's study may have started when it was added).
        $this->studyReferences($user, $c);
        $this->notePages($user, $c, $activity);
        // Questions come before the plan, one at a time, for a new creation only (a change to a plan is planned
        // straight away). Each answer is a message; the next call asks the next question or plans.
        if (! $skipQuestions && self::plannerTask($c) === 'creative') {
            $since = DB::table('create_plans')->where('conversation_id', $id)->max('created_at');
            $asked = DB::table('create_messages')->where('conversation_id', $id)->where('idempotency_key', 'like', 'clarify:%')->when($since, fn ($q) => $q->where('created_at', '>', $since))->count();
            $activity->step('Checking what I need to know');
            $question = app(Planning\Clarifier::class)->question($this->context($user, $c), $asked);
            if ($question) {
                $next = (int) $c->version + 1;
                DB::table('create_messages')->insert(['id' => (string) Str::uuid(), 'conversation_id' => $id, 'role' => 'assistant', 'content' => $question,
                    'idempotency_key' => 'clarify:'.$key, 'request_hash' => hash('sha256', $question), 'sequence' => $next, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('create_conversations')->where('id', $id)->update(['version' => $next, 'updated_at' => now()]);
                $activity->abandon();
                return ['needs_answer' => 'clarify', 'question' => $question];
            }
        }
        // An exact copy runs as long as its reference (up to the 30 s Create makes), unless the user chose a length.
        $settings = json_decode($c->settings_json, true) ?: [];
        $lengthNote = null;
        if (($settings['reference_match'] ?? null) === 'exact' && empty($settings['duration_chosen'])) {
            $refSeconds = DB::table('create_attachments')->join('assets', 'assets.id', '=', 'create_attachments.asset_id')->where('create_attachments.conversation_id', $id)
                ->where('create_attachments.purpose', 'reference')->where('assets.asset_type', 'video')->pluck('assets.metadata_json')
                ->map(fn ($m) => (float) data_get(json_decode((string) $m, true), 'reference_study.duration_seconds', 0))->filter()->first();
            $want = $refSeconds ? (int) max(5, min(30, round($refSeconds))) : null;
            if ($want && $want !== (int) ($settings['duration_seconds'] ?? 15)) {
                DB::table('create_conversations')->where('id', $id)->update(['settings_json' => json_encode(OutputSettings::normalize([...$settings, 'duration_seconds' => $want]))]);
                $c = $this->conversations->conversation($user, $id);
                $lengthNote = 'Length set to '.$want.' s to match the reference ('.round($refSeconds).' s'.($refSeconds > 30 ? '; Create makes up to 30 s' : '').'). Change it in Details.';
            }
        }
        // The planning request is synchronous; unlimited testing allows a longer wait for more inspection.
        $deadline = microtime(true) + (PilotPolicy::unlimited() ? 600 : 100);
        if (PilotPolicy::unlimited()) set_time_limit(640);
        $context = $this->context($user, $c);
        $context['_planner_deadline'] = $deadline;
        $task = self::plannerTask($c);
        $activity->step($task === 'edit' ? 'Updating the plan' : 'Planning the video');
        try {
            $result = $this->planner($task)->plan($context);
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'The planner could not make a plan just now. Nothing was charged; try again.');
        }
        // Only host tool receipts can establish inspection evidence; model-written receipts are discarded.
        $context['_reference_evidence'] = $result['reference_evidence'] ?? [];
        $plan = $this->normalize($result['plan'], $context, (int) $user->workspace_id);
        $plan['planner_task'] = $task;
        if ($lengthNote) $plan['direction_notes'][] = ['text' => $lengthNote, 'provenance' => 'inferred'];
        $shots = count($plan['scenes'] ?? []);
        $activity->relabel($task === 'edit' ? 'Updated the plan' : 'Planned '.$shots.' '.($shots === 1 ? 'shot' : 'shots'));
        // The planner's own short account of what it took from the files and chose.
        foreach (array_slice(array_filter((array) ($result['plan']['highlights'] ?? []), 'is_string'), 0, 3) as $line) $activity->item($line);
        $plan['activity'] = $activity->finish();
        } catch (\Throwable $e) {
            $activity->abandon();
            throw $e;
        }

        return DB::transaction(function () use ($user, $id, $version, $key, $hash, $plan, $result, $briefs) {
            $c = $this->conversations->conversation($user, $id, true);
            abort_unless((int) $c->version === $version, 409, 'The conversation changed while planning. Plan again.');
            $messageId = (string) Str::uuid(); $planId = (string) Str::uuid(); $next = $c->version + 1;
            DB::table('create_messages')->insert(['id' => $messageId, 'conversation_id' => $id, 'role' => 'assistant', 'content' => $plan['summary'],
                'idempotency_key' => 'plan:'.$planId, 'request_hash' => hash('sha256', $plan['summary']), 'sequence' => $next, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('create_plans')->where('conversation_id', $id)->where('status', 'proposed')->update(['status' => 'superseded', 'updated_at' => now()]);
            DB::table('create_plans')->insert(['id' => $planId, 'conversation_id' => $id, 'message_id' => $messageId, 'brief_sequence' => (int) $briefs->last()->sequence,
                'idempotency_key' => $key, 'request_hash' => $hash, 'provider' => mb_substr($result['provider'], 0, 120), 'plan_json' => json_encode($plan),
                'usage_json' => $result['usage'] ? json_encode($result['usage']) : null, 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('create_conversations')->where('id', $id)->update(['version' => $next, 'updated_at' => now()]);
            return $this->present(DB::table('create_plans')->where('id', $planId)->first(), DB::table('create_conversations')->where('id', $id)->first());
        });
    }

    /** The plan has made (or is making) its video: it is frozen; changes are asked for in the chat and planned anew. */
    public static function built(string $planId): bool
    {
        return DB::table('composition_runs')->where('input_json->plan->plan_id', $planId)->where('input_json->build_stage', 'full_video')
            ->whereIn('status', ['queued', 'running', 'cancel_requested', 'preview_ready', 'needs_attention'])->exists();
    }

    public const BUILT_MESSAGE = 'This plan already made its video. Tell me what to change in the chat and I will make a new plan.';

    /** The plan was approved (a step or its video was started from it): its content is frozen from then on. */
    public static function approved(string $planId): bool
    {
        return DB::table('composition_runs')->where('input_json->plan->plan_id', $planId)->exists();
    }

    public const APPROVED_MESSAGE = 'This plan is approved, so it no longer changes. Tell me what to change in the chat and I will make a new plan.';
    /** After approval only the steps move on: the character's looks, notes on frames, and their approvals. */
    public const STEP_FIELDS = ['character_approval', 'storyboard_approval', 'character_looks', 'panel_notes'];

    /** Media whose look must be approved before it is animated: a generated person, their clips, or a cast sheet. */
    public static function lookRequired(array $media): bool
    {
        return collect($media)->contains(fn ($m) => in_array($m['kind'] ?? '', ['character_poses', 'character_variants', 'talking_shot', 'talking_take', 'reference_sheet'], true)
            || (($m['kind'] ?? '') === 'animate_image' && ($m['subject'] ?? '') === 'approved_character'));
    }

    /** Save the user's edits to callouts, choices and kept items. */
    public function select(User $user, string $id, string $planId, int $version, array $input): array
    {
        $this->conversations->authorize($user, true);
        return DB::transaction(function () use ($user, $id, $planId, $version, $input) {
            $c = $this->conversations->conversation($user, $id, true);
            abort_if($c->archived_at || (int) $c->version !== $version, 409, 'Conversation changed. Refresh before editing the plan.');
            $row = DB::table('create_plans')->where('conversation_id', $id)->where('id', $planId)->lockForUpdate()->firstOrFail();
            abort_unless($row->status === 'proposed' && ! $this->stale($row, $c), 409, 'This plan is out of date. Plan again from the latest brief.');
            abort_if(self::built($planId), 409, self::BUILT_MESSAGE);
            abort_if(self::approved($planId) && array_diff(array_keys(array_diff_key($input, ['expected_version' => 1])), self::STEP_FIELDS), 409, self::APPROVED_MESSAGE);
            $plan = json_decode($row->plan_json, true);
            $sel = $plan['selections'];
            if (array_key_exists('callouts', $input)) {
                $sel['callouts'] = array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 120), (array) $input['callouts']), fn ($t) => $t !== ''));
                abort_if(count($sel['callouts']) > 6, 422, 'Use at most six callouts.');
            }
            if (array_key_exists('narration', $input)) {
                $sel['narration'] = array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 160), (array) $input['narration']), fn ($t) => $t !== ''));
                abort_if(count($sel['narration']) > 8, 422, 'Use at most eight narration lines.');
            }
            if (array_key_exists('style', $input)) {
                // The user may switch between the routes this conversation supports.
                $want = (array) $input['style'];
                $ctx = $this->context($user, $c);
                $picked = StylePacks::route(['route' => $want['route'] ?? null, 'pack' => $want['pack'] ?? null, 'why' => 'Chosen by you'], [...$ctx, 'settings' => [...$ctx['settings'], 'style_pack' => null], 'house_style' => ($want['route'] ?? null) === 'saved' ? $ctx['house_style'] : null]);
                abort_unless($picked['route'] === ($want['route'] ?? null), 422, 'That style is not available for this brief.');
                $sel['style'] = $picked;
            }
            if (array_key_exists('look_first', $input)) $sel['look_first'] = (bool) $input['look_first'] || ! empty($plan['look_required']) || self::lookRequired($plan['media'] ?? []);
            if (array_key_exists('engine_overrides', $input)) {
                $sel['engine_overrides'] = collect((array) $input['engine_overrides'])->filter(fn ($e, $n) => (int) $n >= 1 && in_array($e, ShotRoute::ENGINES, true))->all();
            }
            if (array_key_exists('character_looks', $input)) {
                $names = array_column(ShotRoute::sheet(collect($plan['media'] ?? [])->firstWhere('kind', 'reference_sheet') ?? [])['subjects'], 'name');
                $sel['character_looks'] = collect((array) $input['character_looks'])->filter(fn ($look, $name) => in_array($name, $names, true))
                    ->map(fn ($look) => mb_substr(trim((string) $look), 0, 240))->filter()->all();
            }
            if (array_key_exists('panel_notes', $input)) {
                // A note on a storyboard panel redraws that panel (its identity changes); the others are reused.
                $count = count(collect(self::selectedMedia($plan))->firstWhere('kind', 'storyboard')['panels'] ?? []);
                $sel['panel_notes'] = collect((array) $input['panel_notes'])->filter(fn ($note, $n) => (int) $n >= 1 && (int) $n <= $count)
                    ->map(fn ($note) => mb_substr(trim((string) $note), 0, 240))->filter()->all();
            }
            if (array_key_exists('agreement', $input)) {
                $sel['agreement'] = self::agreement($input['agreement']);
                abort_if(empty($sel['agreement']['required']), 422, 'Keep at least one required element: it is what the video is checked against.');
            }
            if (array_key_exists('video_tier', $input)) {
                abort_unless(in_array($input['video_tier'], ['standard', 'premium'], true), 422, 'Choose Standard or Premium.');
                $sel['video_tier'] = $input['video_tier'];
            }
            if (array_key_exists('voice', $input)) {
                abort_unless(\App\Services\Generation\TTS\GeminiVoices::isGeminiVoice((string) $input['voice']) || $input['voice'] === 'clone', 422, 'Choose one of the listed voices.');
                $sel['voice'] = (string) $input['voice'];
            }
            foreach ((array) ($input['choices'] ?? []) as $decision => $option) {
                $d = collect($plan['decisions'])->firstWhere('id', $decision);
                abort_unless($d && collect($d['options'])->firstWhere('id', $option), 422, 'Choose one of the offered options.');
                $sel['choices'][$decision] = $option;
            }
            if (array_key_exists('kept', $input)) {
                $kept = array_values((array) $input['kept']);
                abort_if(array_diff($kept, $plan['kept_as_is']) !== [], 422, 'Only listed items can be kept as-is.');
                $sel['kept'] = $kept;
            }
            if (array_key_exists('asks', $input)) {
                // An upload answers an ask; "go without" leaves its fallback. The file must be one of this conversation's attachments.
                $known = array_column($plan['asks'] ?? [], 'id');
                foreach ((array) $input['asks'] as $a) {
                    abort_unless(in_array($a['id'] ?? '', $known, true), 422, 'That upload request is not in this plan.');
                    if (! empty($a['skip'])) { $sel['asks'][$a['id']] = 'skip'; continue; }
                    $assetId = (int) ($a['asset_id'] ?? 0);
                    abort_unless($assetId && DB::table('create_attachments')->where('conversation_id', $id)->where('asset_id', $assetId)->exists(), 422, 'Attach the file to this conversation first.');
                    $sel['asks'][$a['id']] = $assetId;
                }
            }
            if (array_key_exists('omitted_performance', $input)) {
                $ids = array_values(array_unique((array) $input['omitted_performance']));
                abort_if(array_diff($ids, array_column($plan['character_performance'] ?? [], 'id')) !== [], 422, 'Only listed character actions can be removed.');
                $sel['omitted_performance'] = $ids;
            }
            if (array_key_exists('colours', $input)) {
                // The user's own colours for the planned roles; they stay fixed when the plan is made again.
                abort_unless(is_array($plan['colour_treatment'] ?? null), 422, 'This plan has no colour direction to change.');
                foreach ((array) $input['colours'] as $role => $hex) {
                    abort_unless(isset($plan['colour_treatment']['roles'][$role]), 422, 'Only the planned colour roles can be changed.');
                    if (strtoupper((string) $hex) === $plan['colour_treatment']['roles'][$role]['hex']) continue;
                    $plan['colour_treatment']['roles'][$role] = ['hex' => strtoupper((string) $hex), 'locked' => true];
                    $plan['colour_treatment']['source'] = 'user';
                }
            }
            $plan['selections'] = $sel;
            $plan['credits'] = $this->credits($plan);
            if (array_key_exists('character_approval', $input)) {
                $candidate = CharacterApproval::candidate($this->quotePlan($plan, $planId), json_decode($c->settings_json, true), (int) $c->workspace_id);
                abort_unless($candidate && hash_equals($candidate['token'], (string) $input['character_approval']), 409, 'Character images changed. Review the current images.');
                $plan['selections']['character_approval'] = $candidate['token'];
            }
            if (array_key_exists('storyboard_approval', $input)) {
                // The panels are approved for the character they were drawn from; a new character asks for them again.
                $board = CharacterApproval::board($this->quotePlan($plan, $planId), json_decode($c->settings_json, true), (int) $c->workspace_id);
                abort_unless($board && hash_equals($board['token'], (string) $input['storyboard_approval']), 409, 'The storyboard changed. Review the current frames.');
                $plan['selections']['storyboard_approval'] = $board['token'];
            }
            DB::table('create_plans')->where('id', $planId)->update(['plan_json' => json_encode($plan), 'updated_at' => now()]);
            DB::table('create_conversations')->where('id', $id)->update(['version' => $c->version + 1, 'updated_at' => now()]);
            return $this->present(DB::table('create_plans')->where('id', $planId)->first(), DB::table('create_conversations')->where('id', $id)->first());
        });
    }

    /** The approved plan a quote binds to, or null when there is no current plan. */
    public static function forQuote(object $c): ?array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('create_plans')) return null;
        $row = DB::table('create_plans')->where('conversation_id', $c->id)->where('status', 'proposed')->orderByDesc('created_at')->first();
        if (! $row || (new self(app(ConversationService::class)))->stale($row, $c)) return null;
        return self::quotePlan(json_decode($row->plan_json, true), $row->id);
    }

    public static function quotePlan(array $p, string $planId): array
    {
        $s = $p['selections'];
        $omittedIds = collect($p['character_performance'] ?? [])->filter(fn ($r) => in_array($r['id'], $s['omitted_performance'] ?? [], true))->flatMap(fn ($r) => $r['requirement_ids'] ?? [])->unique()->all();
        $activeRequirements = RequirementContract::excluding($p['requirements'] ?? [], $omittedIds);
        return ['omitted_requirements' => array_values(array_filter($p['requirements'] ?? [], fn ($r) => in_array($r['id'] ?? '', $omittedIds, true))), 'requirements_schema' => $p['requirements_schema'] ?? null, 'requirement_history' => $p['requirement_history'] ?? [], 'direction_notes' => $p['direction_notes'] ?? [], 'reference_evidence' => $p['reference_evidence'] ?? [], 'creative_intent' => $p['creative_intent'] ?? null, 'omitted_character_performance' => array_values(array_filter($p['character_performance'] ?? [], fn ($r) => in_array($r['id'], $s['omitted_performance'] ?? [], true))), 'character_performance' => array_values(array_filter($p['character_performance'] ?? [], fn ($r) => ! in_array($r['id'], $s['omitted_performance'] ?? [], true))), 'reference_observations' => $p['reference_observations'] ?? [], 'character_approval' => $s['character_approval'] ?? null, 'storyboard_approval' => $s['storyboard_approval'] ?? null, 'requirements' => $activeRequirements, 'character_style' => $p['character_style'] ?? '', 'plan_id' => $planId, 'summary' => $p['summary'], 'reused' => $p['reused'], 'scenes' => $p['scenes'],
            'asks' => array_map(fn ($a) => $a + (is_int($s['asks'][$a['id']] ?? null) ? ['asset_id' => $s['asks'][$a['id']]] : (($s['asks'][$a['id']] ?? null) === 'skip' ? ['skipped' => true] : [])), $p['asks'] ?? []),
            'on_screen_copy' => $s['callouts'], 'narration' => $s['narration'] ?? [], 'voice' => $s['voice'] ?? null, 'kept_as_is' => $s['kept'],
            'choices' => collect($p['decisions'])->map(fn ($d) => ['question' => $d['question'], 'chosen' => collect($d['options'])->firstWhere('id', $s['choices'][$d['id']] ?? null)['label'] ?? null])->all(),
            'media' => self::selectedMedia([...$p, 'requirements' => $activeRequirements]), 'left_out' => $p['left_out'], 'style_route' => $s['style'] ?? $p['style'] ?? null, 'colour_treatment' => $p['colour_treatment'] ?? null, 'signature_move' => $p['signature_move'] ?? '', 'look_first' => (bool) ($s['look_first'] ?? $p['look_first'] ?? false), 'video_tier' => $s['video_tier'] ?? 'standard',
            'agreement' => $s['agreement'] ?? $p['agreement'] ?? null]
            // What the build and its checks follow from a reference and the 3D route; without these the builder never sees them.
            + array_intersect_key($p, array_flip(['reference_decisions', 'reference_systems', 'reference_pacing', 'reference_match', 'reference_layout', 'reference_unaccounted', 'mascot3d', 'props3d']));
    }

    public function stale(object $plan, object $c): bool
    {
        $last = DB::table('create_messages')->where('conversation_id', $c->id)->where('role', 'user')->max('sequence');
        return (int) $last !== (int) $plan->brief_sequence;
    }

    public function present(object $row, object $c): array
    {
        $p = json_decode($row->plan_json, true);
        $candidate = CharacterApproval::candidate(self::quotePlan($p, $row->id), json_decode($c->settings_json, true), (int) $c->workspace_id);
        $board = $candidate ? CharacterApproval::board(self::quotePlan($p, $row->id), json_decode($c->settings_json, true), (int) $c->workspace_id, null, $candidate) : null;
        // A talking face or a ready rig attached to the conversation performs without new media.
        $performers = CharacterPerformance::performers($c->id);
        return ['performance_issues' => CharacterPerformance::issues(self::quotePlan($p, $row->id), json_decode($c->settings_json, true), self::selectedMedia($p), $performers), 'character_preview' => $candidate ? ['token' => $candidate['token'], 'images' => array_map(fn ($img, $k) => $img + ['label' => $candidate['names'][$k] ?? null], $candidate['images'], array_keys($candidate['images'])),
            'approved' => hash_equals($candidate['token'], (string) ($p['selections']['character_approval'] ?? '')), 'kind' => $candidate['kind'] ?? null,
            'looks' => $p['selections']['character_looks'] ?? [], 'subjects' => ShotRoute::sheet(collect($p['media'] ?? [])->firstWhere('kind', 'reference_sheet') ?? [])['subjects']] : null,
            'storyboard_preview' => $board ? ['token' => $board['token'], 'images' => $board['images'], 'approved' => hash_equals($board['token'], (string) ($p['selections']['storyboard_approval'] ?? '')),
                'panel_checks' => $board['panel_checks'], 'panel_notes' => $p['selections']['panel_notes'] ?? []] : null, 'id' => $row->id, 'message_id' => $row->message_id, 'status' => $row->status, 'provider' => $row->provider,
            'stale' => $row->status === 'proposed' && $this->stale($row, $c), 'plan' => json_decode($row->plan_json, true), 'created_at' => $row->created_at, 'built' => self::built($row->id), 'approved' => self::approved($row->id),
            // What would be bought as the selections stand: generated shots with their engine, length and price.
            'media_routed' => rescue(fn () => self::selectedMedia($p), [], false)];
    }

    /** What a reference teaches, compact for model context; null when it was never studied. */
    /**
     * Lines whose words are not in the user's brief, approved facts or attached
     * pages. They are not blocked (a headline needs craft), but the plan card
     * marks them so the user sees new wording before approving it.
     */
    public static function newWording(array $lines, array $ctx): array
    {
        $words = fn (string $t) => array_map(fn ($w) => preg_replace('/(ies|es|s)$/', '', $w), preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t), -1, PREG_SPLIT_NO_EMPTY));
        $corpus = implode(' ', [
            ...array_map(fn ($m) => (string) ($m['content'] ?? ''), array_filter($ctx['messages'] ?? [], fn ($m) => ($m['role'] ?? '') === 'user')),
            ...($ctx['approved_facts'] ?? []),
            ...collect($ctx['files'] ?? [])->flatMap(fn ($f) => $f['reference']['page_claims_not_approved'] ?? [])->all(),
        ]);
        $known = array_flip($words($corpus));
        $common = array_flip(['a', 'an', 'the', 'and', 'or', 'to', 'of', 'in', 'on', 'for', 'with', 'your', 'you', 'it', 'is', 'are', 'be', 'that', 'this', 'one', 'no', 'not', 'from', 'into', 'at', 'by', 'we', 'our', 'get', 'make', 'now', 'just', 'all', 'any', 'more', 'how', 'what', 'why', 'two', 'three', 'four', 'five', 'six', 'ready', 'try', 'start', 'today', 'meet', 'say', 'hello', 'got', 'need']);
        return array_values(array_filter(array_unique($lines), function ($line) use ($words, $known, $common) {
            foreach ($words($line) as $w) if (mb_strlen($w) > 2 && ! isset($known[$w]) && ! isset($common[$w]) && ! ctype_digit($w)) return true;
            return false;
        }));
    }

    public static function referenceBrief(Asset $asset): ?array
    {
        $a = data_get($asset->metadata_json, 'reference_analysis');
        $study = data_get($asset->metadata_json, 'reference_study');
        if (! is_array($a) && ! is_array($study)) return null;
        $brief = is_array($a) ? array_filter(['from' => data_get($asset->metadata_json, 'reference_source.platform'), 'duration_seconds' => $a['duration_seconds'] ?? null,
            'cut_candidates_seconds' => array_slice((array) ($a['cuts'] ?? []), 0, 24), 'sampling_limit' => 'Sampled frames and heuristic cuts, not exhaustive motion or audio analysis', 'shots' => $a['shots'] ?? null, 'average_shot_seconds' => $a['average_shot_seconds'] ?? null,
            'speech' => isset($a['transcript']) ? mb_substr((string) $a['transcript'], 0, 600) : null, 'notes' => $a['notes'] ?? null,
            // From a web page: claims the page makes. Not approved; only approved_facts may go on screen.
            'page_claims_not_approved' => ! empty($a['suggested_claims']) ? array_column($a['suggested_claims'], 'text') : null], fn ($v) => $v !== null) : [];
        if (is_array($study)) $brief['study'] = self::studyBrief((int) $asset->id, $study);
        return $brief ?: null;
    }

    /** The study as the planner reads it: moment ids carry the asset id so decisions can name them. */
    public static function studyBrief(int $assetId, array $s): array
    {
        $speech = is_array($s['speech'] ?? null) ? $s['speech'] : null;
        return array_filter([
            'how_to_use' => 'Account for every moment id in reference_decisions. Times are seconds in the reference.',
            'summary' => $s['summary'] ?? null, 'duration_seconds' => $s['duration_seconds'] ?? null, 'coverage' => $s['coverage'] ?? null,
            'video_type' => $s['video_type'] ?? null, 'fps' => $s['fps'] ?? null, 'treatment' => ! empty($s['treatment']['look']) ? $s['treatment'] : null,
            'pacing' => $s['pacing'] ?? null, 'patterns' => $s['patterns'] ?? null,
            'music' => ! empty($s['music']['present']) ? array_intersect_key($s['music'], array_flip(['tempo_bpm', 'beat_seconds', 'cuts_on_beat', 'confidence'])) : null,
            // With a layout pass (copying exactly), each moment also says where its elements sit at its key time.
            'moments' => array_map(fn ($m) => ['id' => $assetId.':'.$m['id']] + (($m['system'] ?? '') !== '' ? ['system' => $assetId.':'.$m['system']] : []) + array_diff_key($m, ['id' => 1, 'system' => 1])
                + (($l = collect(data_get($s, 'layout.moments', []))->firstWhere('moment', $m['id'])) ? ['layout' => array_intersect_key($l, array_flip(['at', 'background', 'elements']))] : []), (array) ($s['moments'] ?? [])),
            'systems' => array_map(fn ($x) => ['id' => $assetId.':'.$x['id']] + array_diff_key($x, ['id' => 1]), (array) ($s['systems'] ?? [])),
            'speech' => $speech ? array_filter(['text' => mb_substr((string) ($speech['text'] ?? ''), 0, 1200), 'first_word_at' => $speech['first_word_at'] ?? null, 'last_word_at' => $speech['last_word_at'] ?? null,
                'pauses' => array_slice((array) ($speech['pauses'] ?? []), 0, 12),
                'timed_words' => mb_substr(collect($speech['words'] ?? [])->map(fn ($w) => $w[1].' '.$w[0])->implode(' | '), 0, 3000)], fn ($v) => $v !== null && $v !== '' && $v !== []) : null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /** Studies any attached reference video that has no study for its current bytes; failures leave planning on the older evidence. */
    private function studyReferences(User $user, object $c): void
    {
        if (config('create.mode') === 'fixture') return;
        foreach (DB::table('create_attachments')->where('conversation_id', $c->id)->where('purpose', 'reference')->pluck('asset_id') as $id) {
            $asset = Asset::where('workspace_id', $user->workspace_id)->find($id);
            if (! $asset || $asset->asset_type !== 'video') continue;
            PlanActivity::current()?->step('Watching '.$asset->title);
            try { $studies = app(\App\Services\Create\References\ReferenceStudy::class); $studies->forAsset($asset, \App\Services\Create\References\ReferenceStudy::coverageMode(json_decode($c->settings_json, true)['reference_effort'] ?? null));
                // Copying exactly: where every element sits at every moment, measured once and cached with the study.
                if ((json_decode($c->settings_json, true)['reference_match'] ?? null) === 'exact') $studies->layoutForAsset($asset->refresh()); }
            catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('Create reference study failed', ['asset' => $id, 'error' => mb_substr($e->getMessage(), 0, 300)]); }
            if ($activity = PlanActivity::current()) {
                $study = data_get($asset->refresh()->metadata_json, 'reference_study');
                $activity->relabel(($study ? 'Watched ' : 'Could not watch ').$asset->title);
                if ($study) {
                    $shots = count((array) ($study['shots'] ?? [])); $seconds = (float) ($study['duration_seconds'] ?? 0);
                    if ($shots && $seconds) $activity->item($shots.' '.($shots === 1 ? 'shot' : 'shots').' over '.round($seconds).' s, a cut about every '.round($seconds / $shots, 1).' s');
                    if (! empty($study['speech'])) $activity->item('Listened to what is said');
                }
            }
        }
    }

    /** Pages read from links since the last plan, as activity: "Read wyvstudio.com" and what the page is. */
    private function notePages(User $user, object $c, PlanActivity $activity): void
    {
        $since = DB::table('create_plans')->where('conversation_id', $c->id)->max('created_at');
        $rows = DB::table('create_attachments')->where('conversation_id', $c->id)->when($since, fn ($q) => $q->where('created_at', '>', $since))->pluck('asset_id');
        foreach (Asset::where('workspace_id', $user->workspace_id)->whereIn('id', $rows)->get() as $asset) {
            $url = (string) data_get($asset->metadata_json, 'reference_source.requested_url', '');
            if ($url === '' || $asset->asset_type === 'video') continue;
            $activity->step('Read '.(parse_url($url, PHP_URL_HOST) ?: $url));
            $activity->item((string) data_get($asset->metadata_json, 'reference_analysis.notes.summary', ''));
        }
    }

    /** Reference frames and the page capture as planner images: [['label' => ..., 'media_type' => ..., 'data' => base64], ...], at most four including a transition sheet. */
    private function planImages(User $user, array $files): array
    {
        $out = []; $transitions = [];
        foreach ($files as $f) {
            if (count($out) >= 9) continue;
            $asset = Asset::where('workspace_id', $user->workspace_id)->find($f['asset_id']);
            if (! $asset) continue;
            // The user's own video is seen too (a sheet of its frames): the plan never places footage it has not looked at.
            if (($f['purpose'] ?? '') === 'source' && $asset->asset_type === 'video') {
                try { if ($path = app(\App\Services\Create\References\ReferenceSheets::class)->pathFor($asset)) $out[] = ['label' => 'Your video "'.$asset->title.'" (asset '.$asset->id.', it goes in the video): '.\App\Services\Create\References\ReferenceSheets::FRAMES.' frames in order, left to right then down', 'media_type' => 'image/jpeg', 'data' => base64_encode((string) \Illuminate\Support\Facades\Storage::disk('local')->get($path))]; } catch (\Throwable) {}
                continue;
            }
            if (($f['purpose'] ?? '') !== 'reference') continue;
            try {
                $study = data_get($asset->metadata_json, 'reference_study');
                if ($asset->asset_type === 'video' && ! empty($study['sheets'])) {
                    // The whole-reference study: frames inside every shot and close-ups where the picture changes.
                    // Maximum effort shows the planner more of the reference (up to six sheets).
                    foreach (array_slice($study['sheets'], 0, in_array($study['coverage_mode'] ?? '', ['maximum', 'every_look'], true) ? 6 : 3) as $k => $sheet) {
                        $bytes = \Illuminate\Support\Facades\Storage::disk('local')->get($sheet['path']);
                        if (is_string($bytes) && $bytes !== '') $out[] = ['label' => 'Reference video "'.$asset->title.'" study sheet '.($k + 1).' of '.count($study['sheets']).': cells left to right, then down, at seconds '.json_encode($sheet['times']), 'media_type' => 'image/jpeg', 'data' => base64_encode($bytes)];
                    }
                } elseif ($asset->asset_type === 'video') {
                    $path = app(\App\Services\Create\References\ReferenceSheets::class)->pathFor($asset);
                    if ($path) $out[] = ['label' => 'Reference video "'.$asset->title.'": '.\App\Services\Create\References\ReferenceSheets::FRAMES.' frames in order, left to right then down', 'media_type' => 'image/jpeg', 'data' => base64_encode((string) \Illuminate\Support\Facades\Storage::disk('local')->get($path))];
                    if ($detail = app(\App\Services\Create\References\ReferenceSheets::class)->transitionsFor($asset)) $transitions[] = ['label' => 'Cut windows for reference '.$asset->id.'; each row is before/at/after, seconds '.json_encode($detail['times']).'. No audio observed.', 'media_type' => 'image/jpeg', 'data' => base64_encode((string) \Illuminate\Support\Facades\Storage::disk('local')->get($detail['path']))];
                } elseif ($asset->asset_type === 'image' && in_array($asset->mime_type, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                    $bytes = app(\App\Services\Media\StorageService::class)->get((string) $asset->storage_url);
                    if (is_string($bytes) && $bytes !== '' && strlen($bytes) <= 1_000_000) $out[] = ['label' => 'Reference image '.$asset->id.': '.$asset->title, 'media_type' => $asset->mime_type, 'data' => base64_encode($bytes)];
                }
            } catch (\Throwable) { /* the notes still describe it */ }
        }
        return array_slice([...$out, ...$transitions], 0, 10);
    }

    /** @return array<int, array{role: string, content: string}> */
    private function messagesOf(object $c): array
    {
        return DB::table('create_messages')->where('conversation_id', $c->id)->orderBy('sequence')->get(['role', 'content', 'sequence'])->map(fn ($m) => (array) $m)->all();
    }

    private function context(User $user, object $c): array
    {
        $settings = json_decode($c->settings_json, true) ?: [];
        $files = DB::table('create_attachments')->where('conversation_id', $c->id)->orderBy('asset_id')->get()->map(function ($a) use ($user) {
            $asset = Asset::where('workspace_id', $user->workspace_id)->find($a->asset_id);
            return $asset ? ['asset_id' => (int) $asset->id, 'title' => (string) $asset->title, 'asset_type' => $asset->asset_type, 'purpose' => $a->purpose,
                'duration_seconds' => $asset->duration_seconds, 'dimensions' => $asset->dimensions_json,
                'reference' => $a->purpose === 'reference' ? self::referenceBrief($asset) : null,
                ...(is_array(data_get($asset->metadata_json, 'rig')) ? ['rig' => data_get($asset->metadata_json, 'rig')] : []),
                ...(is_array(data_get($asset->metadata_json, 'face_kit')) ? ['face_kit' => ['expressions' => array_values(array_map(fn ($p) => (string) ($p['name'] ?? ''), (array) data_get($asset->metadata_json, 'face_kit.patches', [])))]] : [])] : null;
        })->filter()->values()->all();
        return [
            '_workspace_id' => (int) $user->workspace_id,
            'messages' => DB::table('create_messages')->where('conversation_id', $c->id)->orderBy('sequence')->get(['role', 'content', 'sequence'])->map(fn ($m) => (array) $m)->all(),
            // Text and colour fields of the current version; a request that only changes these is free.
            'current_variables' => ($head = $c->head_revision_id ? DB::table('composition_revisions')->where('id', $c->head_revision_id)->value('bundle_json') : null)
                ? array_map(fn ($d) => ['id' => $d['id'], 'type' => $d['type'], 'label' => $d['label'] ?? $d['id'], 'current' => $d['default'] ?? null],
                    array_values(array_filter(CompositionVariables::declarations((string) (json_decode($head, true)['index.html'] ?? '')), fn ($d) => in_array($d['type'] ?? '', ['string', 'color'], true))))
                : [],
            // Voices the narration may use: the catalogue by character, plus the workspace's own clone.
            'voices' => array_merge(array_map(fn ($k) => ['key' => $k, 'character' => \App\Services\Generation\TTS\GeminiVoices::VOICES[$k], 'gender' => \App\Services\Generation\TTS\GeminiVoices::gender($k)], array_keys(\App\Services\Generation\TTS\GeminiVoices::VOICES)),
                \Illuminate\Support\Facades\Schema::hasTable('voice_profiles') && DB::table('voice_profiles')->where('workspace_id', $user->workspace_id)->where('is_cloned', true)->exists() ? [['key' => 'clone', 'character' => "The workspace's own cloned voice", 'gender' => '']] : []),
            'files' => $files, 'settings' => $settings, 'house_style' => StyleService::brief($settings['style_id'] ?? null, (int) $user->workspace_id), 'approved_facts' => $settings['approved_facts'] ?? [],
            // Pictures for the planner (underscored keys never reach the JSON): frames of each studied reference video, and the page capture.
            '_images' => $this->planImages($user, $files),
            // Built-in style packs to start from, and the ones this workspace used last, so the planner varies them.
            'style_packs' => StylePacks::catalogue(),
            // The user's verdicts on earlier videos, per style key (pack:<slug>, saved:<id>, reference, free).
            'style_notes' => app(StyleNotes::class)->all((int) $user->workspace_id),
            // A pack the user pinned: the planner writes the scenes inside its rules.
            'pinned_style_rules' => StylePacks::exists($settings['style_pack'] ?? null) ? (string) file_get_contents(StylePacks::dir().'/'.$settings['style_pack'].'/STYLE.md') : null,
            'recent_style_packs' => DB::table('composition_runs')->where('workspace_id', $user->workspace_id)->orderByDesc('created_at')->limit(6)->pluck('input_json')
                ->map(fn ($j) => data_get(json_decode($j, true), 'style_pack.slug'))->filter()->unique()->take(3)->values()->all(),
            'brand_palettes' => CapabilityCatalogue::brandPalettes((int) $user->workspace_id),
            'tools' => CapabilityCatalogue::forWorkspace((int) $user->workspace_id), 'brand_kits' => CapabilityCatalogue::brandKits((int) $user->workspace_id),
            // Finished registry blocks and components the builder can mount by name; a beat lists what it uses.
            'registry' => RegistryCatalogue::shortlist((string) (collect($this->messagesOf($c))->last()['content'] ?? ''), $settings),
            // The user's edits to the last plan are their decisions; a new plan starts from them.
            'previous_plan' => ($prev = DB::table('create_plans')->where('conversation_id', $c->id)->orderByDesc('created_at')->first())
                ? ['brief_sequence' => (int) $prev->brief_sequence, 'reference_evidence' => json_decode($prev->plan_json, true)['reference_evidence'] ?? [], 'requirement_history' => json_decode($prev->plan_json, true)['requirement_history'] ?? [], 'creative_intent' => json_decode($prev->plan_json, true)['creative_intent'] ?? null, 'approved_narration' => json_decode($prev->plan_json, true)['selections']['narration'] ?? [], 'approved_voice' => json_decode($prev->plan_json, true)['selections']['voice'] ?? null, 'character_performance' => json_decode($prev->plan_json, true)['character_performance'] ?? [], 'omitted_performance' => json_decode($prev->plan_json, true)['selections']['omitted_performance'] ?? [], 'requirements' => json_decode($prev->plan_json, true)['requirements'] ?? [], 'character_style' => json_decode($prev->plan_json, true)['character_style'] ?? '', 'summary' => json_decode($prev->plan_json, true)['summary'] ?? '', 'approved_copy' => json_decode($prev->plan_json, true)['selections']['callouts'] ?? [],
                    'colour_treatment' => json_decode($prev->plan_json, true)['colour_treatment'] ?? null,
                    'video_tier' => json_decode($prev->plan_json, true)['selections']['video_tier'] ?? null,
                    'approved_agreement' => json_decode($prev->plan_json, true)['selections']['agreement'] ?? null,
                    'kept_as_is' => json_decode($prev->plan_json, true)['selections']['kept'] ?? []] : null,
        ];
    }

    public function normalize(array $raw, array $ctx, int $workspaceId): array
    {
        // Cut long text at a word boundary, never mid-word.
        $str = function ($v, int $n) {
            $t = trim(is_string($v) ? $v : '');
            if (mb_strlen($t) <= $n) return $t;
            $cut = mb_substr($t, 0, $n - 1);
            $space = mb_strrpos($cut, ' ');
            return rtrim($space > $n * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,;:-").'…';
        };
        $slug = fn ($v) => mb_substr(preg_replace('/[^a-z0-9_-]/', '', strtolower(is_string($v) ? $v : '')), 0, 32);
        $intent = CreativeIntent::normalize($raw['creative_intent'] ?? null, $ctx);
        $summary = $str($raw['summary'] ?? '', 600);
        abort_if($summary === '', 502, 'The planner returned an empty plan. Nothing was charged; try again.');
        $userText = implode("\n", array_column(array_filter($ctx['messages'] ?? [], fn ($m) => ($m['role'] ?? '') === 'user'), 'content'));
        $contract = RequirementContract::normalize($raw, $ctx);
        $requirements = $contract['requirements'];
        $characterStyle = $str($raw['character_style'] ?? '', 240);
        $image = ($ctx['settings']['output_kind'] ?? 'video') === 'image';
        $duration = (float) ($ctx['settings']['duration_seconds'] ?? 15);
        $sources = collect($ctx['files'])->where('purpose', 'source')->keyBy('asset_id');
        $reused = collect((array) ($raw['reused'] ?? []))->filter(fn ($r) => is_array($r) && $sources->has((int) ($r['asset_id'] ?? 0)))
            ->map(fn ($r) => ['asset_id' => (int) $r['asset_id'], 'title' => $sources[(int) $r['asset_id']]['title'], 'use' => $str($r['use'] ?? '', 120)])->unique('asset_id')->values()->all();
        $scenes = $image ? [] : collect((array) ($raw['scenes'] ?? []))->filter(fn ($s) => is_array($s))->map(fn ($s) => [
            'requirement_ids' => $s['requirement_ids'] ?? [], 'label' => $str($s['label'] ?? '', 40), 'start' => round(max(0, min($duration, (float) ($s['start'] ?? 0))), 1),
            'end' => round(max(0, min($duration, (float) ($s['end'] ?? 0))), 1), 'idea' => $str($s['idea'] ?? '', 160),
            // The director's plan: what is on screen at each end of the beat, and what the viewer must understand, in order.
            'state_in' => $str($s['state_in'] ?? '', 120), 'state_out' => $str($s['state_out'] ?? '', 120),
            'reads' => collect((array) ($s['reads'] ?? []))->map(fn ($r) => $str($r, 90))->filter()->take(4)->values()->all(),
            // Art direction per beat: where things sit and how big, and the colour field behind them.
            'layout' => $str($s['layout'] ?? '', 140), 'field' => $str($s['field'] ?? '', 40),
            // Registry items this beat mounts; only names the sandbox ships.
            'uses' => collect((array) ($s['uses'] ?? []))->filter(fn ($n) => RegistryCatalogue::has(is_string($n) ? $n : null))->unique()->take(2)->values()->all(),
            'starts_on' => $str($s['starts_on'] ?? '', 60),
            // Teaching videos: the beat's step in the arc (hook, familiar, disruption, mechanism, discovery, consequence, recap).
            'arc' => in_array($s['arc'] ?? null, self::ARC, true) ? $s['arc'] : null,
            // What on screen becomes the next scene, and the move that does it (a cut where a cut is right).
            'transition_out' => is_array($s['transition_out'] ?? null) ? array_filter(['from' => $str($s['transition_out']['from'] ?? '', 60), 'becomes' => $str($s['transition_out']['becomes'] ?? '', 60),
                'move' => ($s['transition_out']['move'] ?? '') === 'cut' ? 'cut' : (MotionMoves::valid($s['transition_out']['move'] ?? null) ?? null)]) : null,
        // A step-by-step reference easily needs a title card and a screen per step plus a hook and a close; cutting at 8 silently lost the ending.
        ])->map(fn ($s) => array_filter($s, fn ($v, $k) => ! in_array($k, ['arc', 'transition_out'], true) || ! empty($v), ARRAY_FILTER_USE_BOTH))
            ->filter(fn ($s) => $s['label'] !== '' && $s['end'] > $s['start'])->take(16)->values()->all();
        $callouts = collect((array) ($raw['callouts'] ?? []))->map(fn ($t) => $str($t, 120))->filter()->unique()->take(6)->values()->all();
        $known = collect(CapabilityCatalogue::forWorkspace($workspaceId))->keyBy('kind');
        $decisions = collect((array) ($raw['decisions'] ?? []))->filter(fn ($d) => is_array($d))->map(function ($d) use ($str, $slug, $known) {
            $options = collect((array) ($d['options'] ?? []))->filter(fn ($o) => is_array($o))->map(function ($o) use ($str, $slug, $known) {
                $media = ($o['kind'] ?? '') === 'media' && $known->has($o['tool'] ?? '');
                return ['id' => $slug($o['id'] ?? $o['label'] ?? ''), 'label' => $str($o['label'] ?? '', 60), 'detail' => $str($o['detail'] ?? '', 160),
                    'requirement_ids' => $o['requirement_ids'] ?? [], 'kind' => $media ? 'media' : 'included', 'tool' => $media ? $o['tool'] : null,
                    'subject' => $media && $o['tool'] === 'animate_image' && ($o['subject'] ?? '') === 'approved_character' ? 'approved_character' : 'source',
                    'credits' => $media ? (int) $known[$o['tool']]['credits'] : 0];
            })->filter(fn ($o) => $o['id'] !== '' && $o['label'] !== '')->unique('id')->take(3)->values()->all();
            return ['id' => $slug($d['id'] ?? $d['question'] ?? ''), 'question' => $str($d['question'] ?? '', 120), 'options' => $options];
        })->filter(fn ($d) => $d['id'] !== '' && $d['question'] !== '' && count($d['options']) >= 2)->unique('id')->take(3)->values()->all();
        $kept = collect((array) ($raw['kept_as_is'] ?? []))->map(fn ($t) => $str($t, 80))->filter()->unique()->take(8)->values()->all();
        $media = collect((array) ($raw['media'] ?? []))->filter(fn ($m) => is_array($m) && $known->has($m['kind'] ?? ''))
            ->map(fn ($m) => ['requirement_ids' => $m['requirement_ids'] ?? [], 'kind' => $m['kind'], 'description' => $str($m['description'] ?? '', in_array($m['kind'], ShotRoute::KINDS, true) ? 400 : 200), 'subject' => ($m['kind'] === 'animate_image' && ($m['subject'] ?? '') === 'approved_character') ? 'approved_character' : 'source', 'credits' => (int) $known[$m['kind']]['credits']]
                // Generated video keeps the planner's choice of engine, length, references and slot; ShotRoute checks it.
                + (in_array($m['kind'], ShotRoute::KINDS, true) ? ShotRoute::plannerFields($m) : []))
            // A story told in generated shots needs room: a sheet, several shots, a take, voice, music and sounds.
            ->take(14)
            // A talking shot or take is made from the poses and the narration, so it is always bought after them;
            // generated shots and takes are made from the approved sheet, so after it.
            ->sortBy(fn ($m) => in_array($m['kind'], ['talking_shot', 'talking_take', 'generated_shot', 'ugc_take'], true) ? 1 : 0, SORT_NUMERIC, false)->values()->all();
        // The spoken script: short lines, sized to the video, only when the video should speak.
        $silent = ($ctx['settings']['audio'] ?? 'original') === 'silent';
        // Measured: the catalogue voices speak about 2 words a second with pauses; leave 1.5 s at the end.
        // Room for a natural read: about 2.8 words a second leaving a second at the end (a 15 s video holds 39 words).
        // At 2 words a second the script was cut after a few lines, leaving the narration well short of the video.
        $maxWords = (int) round(max(4, (int) ($ctx['settings']['duration_seconds'] ?? 15) - 1) * 2.8);
        $narration = [];
        // A line over 160 characters is split at its sentences (then clauses), never cut mid-sentence.
        $pieces = [];
        foreach ((array) ($raw['narration'] ?? []) as $line) foreach (self::splitLine(trim(is_string($line) ? $line : ''), 160) as $p) $pieces[] = $p;
        foreach ($pieces as $line) {
            if ($line === '' || count($narration) >= 8) continue;
            $words = str_word_count(implode(' ', [...$narration, $line]));
            if ($words > $maxWords) break;
            $narration[] = $line;
        }
        if ($silent) $narration = [];
        // A beat that starts on spoken words keeps them only when the script says them; the build times the beat by them.
        $said = ' '.trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(implode(' ', $narration)))).' ';
        $scenes = array_map(function ($sc) use ($said) {
            $want = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($sc['starts_on'])));
            if ($want === '' || ! str_contains($said, ' '.$want.' ')) unset($sc['starts_on']);
            return $sc;
        }, $scenes);
        $voiceKeys = array_column($ctx['voices'] ?? [], 'key');
        $voice = in_array($raw['voice'] ?? null, $voiceKeys, true) ? $raw['voice'] : \App\Services\Generation\TTS\GeminiVoices::DEFAULT_VOICE;
        // A timing correction must not rewrite or silently truncate the approved script/voice.
        if (($intent['edit_scope'] ?? '') === 'timing_only') {
            $narration = $silent ? [] : ($ctx['previous_plan']['approved_narration'] ?? $narration);
            $voice = $ctx['previous_plan']['approved_voice'] ?? $voice;
            $callouts = $ctx['previous_plan']['approved_copy'] ?? $callouts;
        }
        // A script needs a voice to say it: make sure the plan buys one.
        if ($narration && ! collect($media)->contains(fn ($m) => in_array($m['kind'], ['voiceover', 'cloned_voiceover'], true))) {
            $kind = $voice === 'clone' && $known->has('cloned_voiceover') ? 'cloned_voiceover' : 'voiceover';
            if ($known->has($kind)) $media[] = ['kind' => $kind, 'description' => 'Narration of the approved script', 'credits' => (int) $known[$kind]['credits']];
        }
        $style = StylePacks::route(is_array($raw['style'] ?? null) ? $raw['style'] : [], $ctx);
        $colour = ColourTreatment::normalize($raw['colour_treatment'] ?? null, $ctx['previous_plan']['colour_treatment'] ?? null);
        $observations = collect((array) ($raw['reference_observations'] ?? []))->filter(fn ($r) => is_array($r) && collect($ctx['files'] ?? [])->contains(fn ($f) => $f['purpose'] === 'reference' && $f['asset_id'] === ($r['asset_id'] ?? null)))->take(4)->map(fn ($r) => ['asset_id' => $r['asset_id'], 'observed' => $str($r['observed'] ?? '', 500), 'preserve' => $str($r['preserve'] ?? '', 400), 'replace' => $str($r['replace'] ?? '', 400), 'uncertain' => $str($r['uncertain'] ?? '', 240), 'evidence_ids' => array_values(array_intersect(array_filter((array) ($r['evidence_ids'] ?? []), 'is_string'), array_column(array_filter($ctx['_reference_evidence'] ?? [], fn ($e) => $e['asset_id'] === $r['asset_id']), 'id'))), 'evidence_status' => 'planner_interpretation_of_samples'])->all();
        $scenes = RequirementContract::bind($scenes, $contract, 'scene');
        $media = RequirementContract::bind($media, $contract, 'task');
        foreach ($decisions as &$decision) {
            foreach ($decision['options'] as &$option) $option['requirement_ids'] = RequirementContract::links($option['requirement_ids'] ?? [], array_column($requirements, null, 'id'), $contract['_aliases']);
            unset($option);
        }
        unset($decision);
        $plan = ['requirements_schema' => RequirementContract::VERSION, 'requirement_history' => $contract['requirement_history'], 'direction_notes' => $contract['direction_notes'], 'reference_evidence' => $contract['reference_evidence'], 'creative_intent' => $intent, 'character_performance' => CharacterPerformance::normalize($raw['character_performance'] ?? [], [...$ctx, '_requirement_contract' => $contract]), 'reference_observations' => $observations, 'colour_treatment' => $colour, 'summary' => $summary, 'reused' => $reused, 'scenes' => $scenes, 'callouts' => $callouts, 'decisions' => $decisions, 'narration' => $narration, 'voice' => $voice,
            'kept_as_is' => $kept, 'media' => $media, 'left_out' => $str($raw['left_out'] ?? '', 300), 'style' => $style, 'signature_move' => $str($raw['signature_move'] ?? '', 160),
            // Design first: one still per beat for approval before the motion. The user can turn it off on the plan card.
            'requirements' => $requirements, 'character_style' => $characterStyle,
            // The user reviews the plan, then the video is built straight away. A separate look stage only when
            // expensive media depends on an approved look (a character image, talking or animated character clips).
            'look_first' => (bool) ($raw['look_first'] ?? false) && collect($media)->contains(fn ($m) => in_array($m['kind'] ?? '', ['character_poses', 'character_variants', 'talking_shot', 'talking_take'], true)
                || (($m['kind'] ?? '') === 'animate_image' && ($m['subject'] ?? '') === 'approved_character')),
            'selections' => ['omitted_performance' => $ctx['previous_plan']['omitted_performance'] ?? [], 'callouts' => $callouts, 'narration' => $narration, 'voice' => $voice, 'style' => $style, 'look_first' => (bool) ($raw['look_first'] ?? false), 'choices' => collect($decisions)->mapWithKeys(fn ($d) => [$d['id'] => $d['options'][0]['id']])->all(), 'kept' => $kept]];
        // The reference study's moments: every one gets an explicit keep, replace or drop, and nothing disappears silently.
        $known = collect($ctx['files'] ?? [])->flatMap(fn ($f) => collect(data_get($f, 'reference.study.moments', []))->pluck('id'))->filter()->values()->all();
        $refDecisions = collect((array) ($raw['reference_decisions'] ?? []))->filter(fn ($d) => is_array($d) && in_array($d['moment'] ?? null, $known, true) && in_array($d['decision'] ?? null, ['keep', 'replace', 'drop'], true))
            ->unique('moment')->map(fn ($d) => ['moment' => $d['moment'], 'decision' => $d['decision'], 'beat' => $str($d['beat'] ?? '', 40), 'how' => $str($d['how'] ?? '', 140)]
                + ($d['decision'] === 'drop' && $str($d['carried_by'] ?? '', 140) !== '' ? ['carried_by' => $str($d['carried_by'], 140)] : [])
                + ($d['decision'] !== 'drop' && ($move = MotionMoves::valid($d['move'] ?? null)) ? ['move' => $move] : []))->values()->all();
        $plan['reference_decisions'] = $refDecisions;
        // Recurring systems: one spec each, so every occurrence is built the same way. The study's own description travels with it.
        $systems = collect($ctx['files'] ?? [])->flatMap(fn ($f) => collect(data_get($f, 'reference.study.systems', [])))->keyBy('id');
        $plan['reference_systems'] = collect((array) ($raw['reference_systems'] ?? []))->filter(fn ($x) => is_array($x) && $systems->has($x['system'] ?? null) && in_array($x['decision'] ?? null, ['keep', 'adapt', 'drop'], true))
            // The move the build must use: the planner's, else the study's own reading of the frames.
            ->unique('system')->map(fn ($x) => ['system' => $x['system'], 'name' => $systems[$x['system']]['name'] ?? '', 'decision' => $x['decision'], 'spec' => $str($x['spec'] ?? '', 260)]
                + ($x['decision'] !== 'drop' && ($move = MotionMoves::valid($x['move'] ?? null) ?? MotionMoves::valid($systems[$x['system']]['move'] ?? null)) ? ['move' => $move] : []) + [
                'beats' => collect((array) ($x['beats'] ?? []))->map(fn ($b) => $str($b, 40))->filter()->take(12)->values()->all(),
                'reference' => array_intersect_key($systems[$x['system']], array_flip(['look', 'entry', 'active', 'hold', 'exit']))])->take(12)->values()->all();
        // A parametric 3D mascot the planner designed for this brand (parts only, no media to buy).
        if ($mascot = MascotSpec::normalize(data_get($raw, 'mascot3d.spec'))) {
            $plan['mascot3d'] = ['spec' => $mascot, 'why' => $str(data_get($raw, 'mascot3d.why', ''), 160)]
                + (($missing = $str(data_get($raw, 'mascot3d.missing', ''), 200)) !== '' ? ['missing' => $missing] : []);
            // The 3D mascot is the character: its turnaround in the look stage is the approval, so no image of it is bought.
            $plan['media'] = array_values(array_filter($plan['media'], fn ($m) => ! in_array($m['kind'] ?? '', ['character_poses', 'character_variants'], true)));
        }
        // 3D objects the build models in code (a laptop, a book stack, a bottle), in the mascot's finish, at no media cost.
        $props = collect((array) ($raw['props3d'] ?? []))->filter(fn ($x) => is_array($x) && trim((string) ($x['name'] ?? '')) !== '')
            ->map(fn ($x) => ['name' => $str($x['name'], 40), 'looks' => $str($x['looks'] ?? '', 200),
                'moments' => collect((array) ($x['moments'] ?? []))->map(fn ($m) => $str($m, 40))->filter()->take(12)->values()->all(),
                'spin' => (bool) ($x['spin'] ?? false)])->unique('name')->take(8)->values()->all();
        if ($props) $plan['props3d'] = $props;
        // A plan that buys a look-dependent person or clip always makes still frames for approval first (a 3D mascot
        // has dropped any character image by now). Anything else goes straight to the video unless the user asked to
        // see the look first (the planner's flag), and the user can switch that on the plan.
        $plan['look_required'] = self::lookRequired($plan['media']);
        // A 3D mascot's turnaround on the plan is its look, so the flag is not followed for it.
        $plan['look_first'] = $plan['look_required'] || ((bool) ($raw['look_first'] ?? false) && empty($plan['mascot3d']));
        $plan['selections']['look_first'] = $plan['look_first'];
        // Generated video: what its routing depends on, and the cast/world sheet is approved before any clip is bought.
        if (collect($plan['media'])->contains(fn ($m) => in_array($m['kind'] ?? '', ShotRoute::KINDS, true))) {
            $plan['shot_context'] = ['has_avatar' => collect($ctx['files'] ?? [])->contains(fn ($f) => ($f['purpose'] ?? '') === 'source' && ($f['asset_type'] ?? '') === 'image'),
                'aspect_ratio' => $ctx['settings']['aspect_ratio'] ?? '9:16', 'language' => $ctx['settings']['language'] ?? 'en'];
            $plan['selections']['video_tier'] = in_array($ctx['previous_plan']['video_tier'] ?? null, ['standard', 'premium'], true) ? $ctx['previous_plan']['video_tier'] : 'standard';
            if (collect($plan['media'])->contains('kind', 'reference_sheet')) $plan['look_first'] = $plan['selections']['look_first'] = $plan['look_required'] = true;
        }
        // How closely the build follows the reference (Details, the brief, or the user's answer to the planner's question).
        if (! empty($ctx['settings']['reference_match']) && $refDecisions) $plan['reference_match'] = $ctx['settings']['reference_match'];
        // Copying exactly: each kept or replaced moment becomes something the build is checked against, at its own
        // time and with the reference's element slots; only the content inside the slots changes.
        if (($plan['reference_match'] ?? null) === 'exact') {
            $studied = collect($ctx['files'] ?? [])->flatMap(fn ($f) => collect(data_get($f, 'reference.study.moments', [])))->keyBy('id');
            $plan['reference_layout'] = collect($refDecisions)->filter(fn ($d) => $d['decision'] !== 'drop' && $studied->has($d['moment']))->map(function ($d) use ($studied) {
                $m = $studied[$d['moment']];
                return ['moment' => $d['moment'], 'beat' => $d['beat'], 'start' => $m['start'] ?? null, 'end' => $m['end'] ?? null,
                    'at' => data_get($m, 'layout.at', $m['start'] ?? null), 'move' => $d['move'] ?? ($m['move'] ?? null), 'content' => $d['how'],
                    'background' => data_get($m, 'layout.background'), 'elements' => array_values((array) data_get($m, 'layout.elements', []))];
            })->values()->take(40)->all();
        }
        $plan['reference_unaccounted'] = array_values(array_diff($known, array_column($refDecisions, 'moment')));
        // Real things only the user has (screens, logo, photos, people, recordings): at most five, each with its beat and fallback.
        $labels = array_column($scenes, 'label');
        $plan['asks'] = collect((array) ($raw['asks'] ?? []))->filter(fn ($a) => is_array($a) && $str($a['what'] ?? '', 80) !== '' && in_array($a['kind'] ?? '', ['screen', 'logo', 'photo', 'recording', 'person'], true))
            ->map(fn ($a) => ['id' => 'ask-'.substr(hash('sha256', mb_strtolower($str($a['what'], 80)).'|'.($a['beat'] ?? '')), 0, 8), 'what' => $str($a['what'], 80), 'kind' => $a['kind'], 'why' => $str($a['why'] ?? '', 120),
                'beat' => in_array($a['beat'] ?? '', $labels, true) ? $a['beat'] : '', 'moments' => array_values(array_intersect((array) ($a['moments'] ?? []), $known)), 'fallback' => $str($a['fallback'] ?? '', 120)])
            ->unique('id')->take(5)->values()->all();
        // The reference's rhythm travels to the build (and to the comparison after the render).
        $studied = collect($ctx['files'] ?? [])->first(fn ($f) => is_array(data_get($f, 'reference.study.pacing')));
        if ($studied) $plan['reference_pacing'] = array_filter(array_intersect_key((array) data_get($studied, 'reference.study.pacing'), array_flip(['average_shot_seconds', 'cuts_per_10_seconds', 'words_per_second', 'text_to_speech_delay_seconds']))
            + (is_array(data_get($studied, 'reference.study.music')) ? ['tempo_bpm' => data_get($studied, 'reference.study.music.tempo_bpm'), 'cuts_on_beat' => data_get($studied, 'reference.study.music.cuts_on_beat')] : []), fn ($v) => $v !== null);
        // Length from narration: a script that fills clearly less of the video than its length leads to a stated choice, not silent holds.
        $words = str_word_count(implode(' ', $narration));
        $videoSeconds = (float) ($ctx['settings']['duration_seconds'] ?? 0);
        $plan['length_choice'] = $str($raw['length_choice'] ?? '', 160) ?: null;
        $plan['length_note'] = $words > 0 && $videoSeconds > 0 && $words / 2.4 < 0.85 * $videoSeconds
            ? 'The narration runs about '.round($words / 2.4).' s of this '.round($videoSeconds).' s video.'.($plan['length_choice'] ? ' '.$plan['length_choice'] : ' Choose a slower voice, a shorter video or more script, or the rest is music-only holds.')
            : null;
        // A text/colour-only request becomes a free edit, validated against the real fields.
        $free = [];
        if (is_array($raw['free_edit'] ?? null) && ! empty($ctx['current_variables'])) {
            $decls = array_map(fn ($v) => ['id' => $v['id'], 'type' => $v['type'], 'default' => $v['current']], $ctx['current_variables']);
            try { $free = CompositionVariables::validate($decls, $raw['free_edit']); } catch (\Symfony\Component\HttpKernel\Exception\HttpException) { $free = []; }
        }
        $plan['free_edit'] = $free ?: null;
        $plan['new_wording'] = self::newWording([...$callouts, ...$narration], $ctx);
        // What is being made (M) and what sets the timing (B4), from what the plan actually makes.
        $plan['video_type'] = self::videoType($plan, $ctx);
        if (collect($plan['media'])->contains(fn ($m) => in_array($m['kind'] ?? '', ['ugc_take', 'talking_take'], true)) && is_array($plan['creative_intent'] ?? null))
            $plan['creative_intent']['timing_driver'] = 'narration'; // approved speech is never re-timed to fit generated cuts
        // What stays and what changes (M1): shown on the plan for correction, and followed by the build and its checks.
        $plan['agreement'] = self::agreement(is_array($ctx['previous_plan']['approved_agreement'] ?? null) && ! empty(array_filter($ctx['previous_plan']['approved_agreement'])) && empty($raw['agreement'])
            ? $ctx['previous_plan']['approved_agreement'] : ($raw['agreement'] ?? []));
        // Never empty: the user's own requirements are what must appear when the planner listed nothing.
        if (! $plan['agreement']['required']) $plan['agreement']['required'] = collect($requirements)->where('provenance', 'user')->pluck('text')->map(fn ($t) => mb_substr((string) $t, 0, 120))->take(5)->values()->all();
        $plan['selections']['agreement'] = $plan['agreement'];
        $plan['credits'] = $this->credits($plan);
        return $plan;
    }

    /**
     * The video type (todo M), from what the plan makes rather than the planner's word for it: a presenter speaking,
     * footage (generated, stock or the user's), and designed graphics (callouts, UI, kinetic type) in any mix.
     */
    /** A line split into pieces of at most $max characters: at sentence ends, then commas, then words. */
    public static function splitLine(string $line, int $max): array
    {
        if (mb_strlen($line) <= $max) return [$line];
        foreach (['/(?<=[.!?])\s+/u', '/(?<=[,;:])\s+/u', '/\s+/u'] as $at) {
            $parts = preg_split($at, $line); if (count($parts) < 2) continue;
            $out = []; $cur = '';
            foreach ($parts as $p) {
                if ($cur !== '' && mb_strlen($cur.' '.$p) > $max) { $out[] = $cur; $cur = $p; } else $cur = $cur === '' ? $p : $cur.' '.$p;
            }
            if ($cur !== '') $out[] = $cur;
            if (max(array_map('mb_strlen', $out)) <= $max) return $out;
            return array_merge(...array_map(fn ($o) => self::splitLine($o, $max), $out));
        }
        return mb_str_split($line, $max);
    }

    /** The teaching arc's steps (educational format), in order. */
    public const ARC = ['hook', 'familiar', 'disruption', 'mechanism', 'discovery', 'consequence', 'recap'];

    public static function videoType(array $plan, array $ctx): string
    {
        $kinds = array_column($plan['media'] ?? [], 'kind');
        // A person or character on camera from a video model. A face kit is a talking face drawn in code: animation.
        $presenter = (bool) array_intersect($kinds, ['ugc_take', 'talking_take', 'talking_shot']);
        $footage = (bool) array_intersect($kinds, ['generated_shot', 'stock_video', 'animate_image'])
            || collect($ctx['files'] ?? [])->contains(fn ($f) => ($f['purpose'] ?? '') === 'source' && ($f['asset_type'] ?? '') === 'video');
        $graphics = ! empty($plan['callouts']) || ! empty($plan['mascot3d']) || ! empty($plan['props3d']) || collect($ctx['files'] ?? [])->contains(fn ($f) => ! empty($f['face_kit'])) || (! $presenter && ! $footage);
        return match (true) { $presenter && $graphics => 'ugc_motion', $presenter => 'ugc', $footage && $graphics => 'footage_motion', $footage => 'footage', default => 'motion_graphics' };
    }

    /** The four short lists of the intent agreement, cleaned: at most 6 items each, each under 120 characters. */
    public static function agreement(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach (['preserve', 'replace', 'flexible', 'required'] as $k) $out[$k] = collect((array) ($raw[$k] ?? []))
            ->map(fn ($t) => mb_substr(trim(is_string($t) ? $t : ''), 0, 120))->filter()->unique()->take(6)->values()->all();
        return $out;
    }

    /** Resolve selected purchases once, for both pricing and execution. Dependencies come first. */
    public static function selectedMedia(array $plan): array
    {
        $items = $plan['media'] ?? [];
        foreach ($plan['decisions'] ?? [] as $decision) {
            $option = collect($decision['options'])->firstWhere('id', $plan['selections']['choices'][$decision['id']] ?? null);
            if (($option['kind'] ?? '') !== 'media') continue;
            abort_unless(in_array($option['tool'] ?? null, PlanMediaExecutor::KINDS, true), 422, 'This plan option has no supported media tool. Plan again.');
            $matched = false;
            foreach ($items as &$item) {
                if ($item['kind'] === $option['tool'] && ($option['tool'] !== 'animate_image' || ($item['subject'] ?? 'source') === ($option['subject'] ?? 'source'))) {
                    if (! empty($option['requirement_ids'])) $item['requirement_ids'] = array_values(array_unique(array_merge($item['requirement_ids'] ?? [], $option['requirement_ids'])));
                    $matched = true; break;
                }
            }
            unset($item);
            if (! $matched) {
                $items[] = ['requirement_ids' => $option['requirement_ids'] ?? [], 'kind' => $option['tool'], 'description' => $option['detail'] ?: $option['label'], 'subject' => $option['subject'] ?? 'source', 'credits' => (int) $option['credits']];
            }
        }
        $voice = $plan['selections']['voice'] ?? $plan['voice'] ?? null;
        // Generated shots and takes: engine, length, slot and price from what the models can do and the user's tier.
        $shotCtx = ['video_tier' => $plan['selections']['video_tier'] ?? 'standard', 'has_avatar' => (bool) data_get($plan, 'shot_context.has_avatar', false),
            'has_sheet' => collect($items)->contains('kind', 'reference_sheet'), 'aspect_ratio' => data_get($plan, 'shot_context.aspect_ratio', '9:16'), 'language' => data_get($plan, 'shot_context.language', 'en'),
            'narration' => $plan['selections']['narration'] ?? $plan['narration'] ?? [],
            'original_narration' => $plan['narration'] ?? $plan['selections']['narration'] ?? [],
            'subjects' => array_column(ShotRoute::sheet(collect($items)->firstWhere('kind', 'reference_sheet') ?? [])['subjects'], 'name'), 'voice' => $voice];
        // With a cast sheet, every generated shot starts from its approved storyboard panel (unless the planner chose
        // another start frame), and the panels are drawn in the look stage from the cast.
        // An engine the user chose for a shot (after a refusal, C3) replaces the planner's.
        $n = 0;
        foreach ($items as &$shotItem) if ($shotItem['kind'] === 'generated_shot') { $n++; if ($o = $plan['selections']['engine_overrides'][$n] ?? $plan['selections']['engine_overrides'][(string) $n] ?? null) $shotItem['engine'] = $o; }
        unset($shotItem);
        $boarded = $shotCtx['has_sheet'] && collect($items)->contains('kind', 'generated_shot');
        if ($boarded) {
            $n = 0;
            // The panel is drawn from the cast the planner named (or the whole sheet); the shot itself may then start from the panel alone.
            foreach ($items as &$shotItem) if ($shotItem['kind'] === 'generated_shot') { $n++; $shotItem['panel_refs'] = $shotItem['refs'] ?? ['sheet']; if (empty($shotItem['first_frame'])) $shotItem['first_frame'] = 'Panel '.$n; }
            unset($shotItem);
            $shotCtx['panels'] = array_map(fn ($i) => 'Panel '.$i, range(1, $n));
        }
        foreach ($items as &$shotItem) {
            if (! in_array($shotItem['kind'], ShotRoute::KINDS, true) || $shotItem['kind'] === 'storyboard') continue;
            $shotItem = array_merge($shotItem, match ($shotItem['kind']) {
                'reference_sheet' => ShotRoute::sheet($shotItem, (array) ($plan['selections']['character_looks'] ?? [])),
                'generated_shot' => ShotRoute::shot($shotItem, $shotCtx),
                'ugc_take' => ShotRoute::take($shotItem, $shotCtx),
            });
        }
        unset($shotItem);
        if ($boarded) {
            // The storyboard is drawn right after the cast it depends on.
            $board = ShotRoute::storyboard(array_values(array_filter($items, fn ($m) => $m['kind'] === 'generated_shot')), (array) ($plan['selections']['panel_notes'] ?? []));
            $at = array_search('reference_sheet', array_column($items, 'kind'), true);
            array_splice($items, $at + 1, 0, [$board]);
        }
        // A UGC take speaks the script itself: no separate narration is bought, unless a cloned voice is selected, when
        // the cloned narration is what the take lip-syncs to.
        if (collect($items)->contains('kind', 'ugc_take') && $voice !== 'clone') $items = array_values(array_filter($items, fn ($m) => ! in_array($m['kind'], ['voiceover', 'cloned_voiceover'], true)));
        if (collect($items)->contains('kind', 'ugc_take') && $voice === 'clone' && ! collect($items)->contains(fn ($m) => in_array($m['kind'], ['voiceover', 'cloned_voiceover'], true)))
            $items[] = ['kind' => 'cloned_voiceover', 'description' => 'Narration in your cloned voice for the lip-synced take', 'credits' => \App\Services\CreditService::TTS_CLONE];
        $hasTake = collect($items)->contains('kind', 'talking_take');
        if (($hasTake || (collect($items)->contains('kind', 'talking_shot') && count($plan['selections']['narration'] ?? $plan['narration'] ?? []) <= 1)) && $voice !== 'clone') {
            $items = array_values(array_filter($items, fn ($m) => ! in_array($m['kind'], ['voiceover', 'cloned_voiceover'], true)));
        }
        return collect($items)->map(function ($m) use ($voice, $plan) {
            if (in_array($m['kind'], ['talking_shot', 'talking_take'], true)) $m = array_merge($m, TalkingPresenter::route($m['kind'], $voice));
            if ($voice !== 'clone' && $m['kind'] === 'cloned_voiceover') {
                $m['kind'] = 'voiceover';
                $m['credits'] = \App\Services\CreditService::TTS_GEMINI;
            }
            if ($voice === 'clone' && $m['kind'] === 'voiceover') {
                $m['kind'] = 'cloned_voiceover';
                $m['credits'] = \App\Services\CreditService::TTS_CLONE;
            }
            if (($plan['requirements_schema'] ?? null) === RequirementContract::VERSION) {
                $m['id'] ??= 'task-'.substr(hash('sha256', $m['kind'].'|'.$m['description']), 0, 20);
                $m['requirements'] = RequirementContract::targets($m, $plan);
                $m['requirement_ids'] = array_column($m['requirements'], 'id');
            }
            return $m;
        })->sortBy(fn ($m) => in_array($m['kind'], ['talking_shot', 'talking_take', 'generated_shot', 'ugc_take'], true) ? 1 : 0)->values()->all();
    }

    /** Media the plan would add on top of building the composition. */
    private function credits(array $plan): array
    {
        return ['media' => array_sum(array_column(self::selectedMedia($plan), 'credits'))];
    }
}

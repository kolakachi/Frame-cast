<?php

namespace App\Services\Create;

use App\Models\{ApiQuote, Asset, User, Workspace};
use App\Services\Developer\{OperationAccounting, OperationFence};
use Illuminate\Support\Facades\{Context, DB};
use Illuminate\Support\Str;

/** App-owned state. The model cannot approve a quote or advance a head revision. */
class ConversationService
{
    public const ACTIVE = ['queued', 'running', 'cancel_requested', 'needs_attention'];

    public function creditAvailability(User $user): array
    {
        $workspace = Workspace::findOrFail($user->workspace_id);
        $pool = Workspace::findOrFail($workspace->creditRootId());
        $total = (int) $pool->creditsBalance();
        $reserved = OperationAccounting::reserved($pool->id);
        return ['total' => $total, 'reserved' => $reserved, 'available' => max(0, $total - $reserved)];
    }

    public function authorize(User $user, bool $write = false): void
    {
        abort_unless(app()->environment(['local', 'testing']) && config('create.enabled')
            && in_array((int) $user->workspace_id, config('create.workspaces', []), true), 404);
        abort_if($write && ! in_array($user->role, ['owner', 'admin', 'editor', 'super_admin', 'platform_admin', 'client_admin', 'client_editor'], true), 403);
        $workspace = Workspace::findOrFail($user->workspace_id);
        abort_if($workspace->status !== 'active', 403);
    }

    public function conversation(User $user, string $id, bool $lock = false): object
    {
        $this->authorize($user);
        return DB::table('create_conversations')->where('workspace_id', $user->workspace_id)->where('id', $id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public function create(User $user, array $settings): object
    {
        $this->authorize($user, true);
        if(isset($settings['origin_revision_id'])) {
            $origin=$this->conversation($user,$settings['origin_conversation_id']??'');
            abort_unless(DB::table('composition_revisions')->where('conversation_id',$origin->id)->where('id',$settings['origin_revision_id'])->exists(),404);
        }
        $id = (string) Str::uuid();
        DB::table('create_conversations')->insert([
            'id' => $id, 'workspace_id' => $user->workspace_id, 'created_by_user_id' => $user->id,
            'title' => 'New creation', 'settings_json' => json_encode(OutputSettings::normalize($settings)), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->conversation($user, $id);
    }

    public function message(User $user, string $id, array $input): object
    {
        $this->authorize($user, true);
        return DB::transaction(function () use ($user, $id, $input) {
            $c = $this->conversation($user, $id, true);
            abort_if($c->archived_at, 409, 'Restore this conversation before editing.');
            $hash = hash('sha256', json_encode($input));
            $old = DB::table('create_messages')->where('conversation_id', $id)->where('idempotency_key', $input['idempotency_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'This request key already belongs to a different message.');
                return $old;
            }
            abort_unless((int) $c->version === $input['expected_version'], 409, 'Conversation changed. Refresh before sending.');
            $messageId = (string) Str::uuid();
            $version = $c->version + 1;
            DB::table('create_messages')->insert([
                'id' => $messageId, 'conversation_id' => $id, 'role' => 'user', 'content' => $input['content'],
                'idempotency_key' => $input['idempotency_key'], 'request_hash' => $hash, 'sequence' => $version, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $updates = ['title' => $c->title === 'New creation' ? Str::limit($input['content'], 80, '') : $c->title];
            // Settings the brief states in words are applied now, so the next
            // quote reflects them; anything unsupported becomes a question in
            // the conversation instead of a silent approximation. Each reply
            // advances the version like any other change, which invalidates
            // an older quote the same way a settings edit does.
            $settings = json_decode($c->settings_json, true) ?: [];
            $inferred = BriefSettings::infer($input['content'], $settings);
            $replies = [];
            if ($inferred['changes'] !== []) {
                $updates['settings_json'] = json_encode(OutputSettings::normalize(array_merge($settings, $inferred['changes'])));
                $replies[] = BriefSettings::describe($inferred['changes']);
            }
            array_push($replies, ...$inferred['questions']);
            foreach ($replies as $i => $reply) {
                $version++;
                DB::table('create_messages')->insert([
                    'id' => (string) Str::uuid(), 'conversation_id' => $id, 'role' => 'assistant', 'content' => $reply,
                    'idempotency_key' => $messageId.':reply:'.$i, 'request_hash' => hash('sha256', $reply), 'sequence' => $version, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('create_conversations')->where('id', $id)->update($updates + ['version' => $version, 'updated_at' => now()]);
            return DB::table('create_messages')->where('id', $messageId)->first();
        });
    }

    public function attach(User $user, string $id, int $assetId, string $purpose, int $version): void
    {
        $this->authorize($user, true);
        DB::transaction(function () use ($user, $id, $assetId, $purpose, $version) {
            $c = $this->conversation($user, $id, true);
            abort_if($c->archived_at || (int) $c->version !== $version, 409, 'Conversation changed. Refresh first.');
            $asset = Asset::where('workspace_id', $user->workspace_id)->whereKey($assetId)->firstOrFail();
            abort_unless(in_array($asset->asset_type, ['video', 'image', 'audio'], true) && $asset->status !== 'archived', 422, 'Choose an available image, audio or video.');
            $existing = DB::table('create_attachments')->where('conversation_id', $id)->where('asset_id', $assetId)->exists();
            abort_if(! $existing && DB::table('create_attachments')->where('conversation_id', $id)->count() >= 20, 422, 'Use at most 20 attachments.');
            DB::table('create_attachments')->updateOrInsert(['conversation_id' => $id, 'asset_id' => $assetId], [
                'purpose' => $purpose, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('create_conversations')->where('id', $id)->update(['version' => $c->version + 1, 'updated_at' => now()]);
        });
    }

    public function quote(User $user, string $id, int $version, ?string $buildStage = null): ApiQuote
    {
        $this->authorize($user, true);
        abort_unless($buildStage === null || in_array($buildStage, ['storyboard', 'full_video'], true), 422, 'Choose storyboard or full video.');
        // Never present a fixture as AI output or silently enable an unpriced provider.
        abort_unless(config('create.mode') === 'fixture' || (config('create.mode') === 'agent' && PilotPolicy::enabled()), 503, 'Paid local generation is not enabled.');
        $c = $this->conversation($user, $id);
        abort_if(config('create.mode') === 'fixture' && (json_decode($c->settings_json,true)['output_kind']??'video') === 'image',422,'Image generation is not enabled in this local preview. Your image brief is saved.');
        abort_if($c->archived_at || (int) $c->version !== $version, 409, 'Conversation changed. Review a fresh plan.');
        $settings=json_decode($c->settings_json,true);
        if(config('create.mode')==='agent' && ($settings['output_kind']??'video')==='video') {
            $workspace=Workspace::findOrFail($user->workspace_id);
            abort_if((\App\Services\WorkspaceUsageService::plans()[$workspace->plan_tier]['watermark']??true),402,'This local video pilot requires a plan with unwatermarked exports.');
        }
        $attachments = DB::table('create_attachments')->where('conversation_id', $id)->orderBy('id')->get(['asset_id', 'purpose'])->all();
        if(config('create.mode')==='agent' && ($settings['output_kind']??'video')==='image' && $c->head_revision_id) {
            $base=DB::table('composition_revisions')->where('conversation_id',$id)->where('id',$c->head_revision_id)->firstOrFail();
            abort_unless($base->output_asset_id,422,'Save this image to Assets before editing it.');
            $oldRun=$base->run_id ? DB::table('composition_runs')->where('id',$base->run_id)->first() : null;
            $usedIds=array_column(json_decode($oldRun->input_json??'{}',true)['input_files']??[],'asset_id');
            $attachments=array_merge([(object)['asset_id'=>$base->output_asset_id,'purpose'=>'source']],array_values(array_filter($attachments,fn($a)=>!in_array($a->asset_id,$usedIds,true) && $a->asset_id!==$base->output_asset_id))); 
        }
        $snapshots = app(InputSnapshotService::class);
        // Storage I/O happens before acquiring conversation/pool locks.
        $inherited = ($settings['output_kind']??'video')==='image' ? [] : $snapshots->inherited($id, $c->head_revision_id);
        $snapshots->verify($inherited);
        $inheritedIds = array_column($inherited, 'asset_id');
        // A revision's source is immutable, even if its library item was replaced.
        foreach ($attachments as $attachment) {
            $previous = collect($inherited)->firstWhere('asset_id', $attachment->asset_id);
            abort_if($previous && $previous['purpose'] !== $attachment->purpose, 409, 'Start a new conversation to change an existing source to reference-only or vice versa.');
        }
        $newFiles = $snapshots->capture((int) $user->workspace_id, array_values(array_filter($attachments, fn ($a) => ! in_array($a->asset_id, $inheritedIds, true))));
        $files = array_merge($inherited, $newFiles);
        if (count($files) > 20 || array_sum(array_column($files, 'bytes')) > config('create.input_total_bytes')) {
            $snapshots->discard($newFiles);
            abort(422, 'Inherited and new attachments exceed the local preview size limit.');
        }
        try {
            return DB::transaction(function () use ($user, $id, $version, $files, $attachments, $buildStage) {
                $c = $this->conversation($user, $id, true);
                abort_if($c->archived_at || (int) $c->version !== $version, 409, 'Conversation changed. Review a fresh plan.');
                $messages = DB::table('create_messages')->where('conversation_id', $id)->orderBy('sequence')->get(['id', 'sequence', 'role', 'content'])->all();
                abort_if(! count($messages), 422, 'Add a brief first.');
                $base = $c->head_revision_id ? DB::table('composition_revisions')->where('conversation_id', $id)->where('id', $c->head_revision_id)->firstOrFail() : null;
                $settings=json_decode($c->settings_json,true);
                $paid=config('create.mode')==='agent';
                $policy=$paid ? PilotPolicy::execution($settings) : ['agent'=>['provider'=>'offline','model'=>'offline-contract-v1','credits'=>0,'cost_limit_microusd'=>0,'max_calls'=>6],'render'=>['provider'=>'offline','model'=>'hyperframes-0.8.82','credits'=>0,'cost_limit_microusd'=>0,'max_calls'=>1]];
                if($paid && ($settings['output_kind']??'video')==='image') {
                    abort_if(count(array_filter($files,fn($f)=>$f['asset_type']!=='image'))>0,422,'Image generation accepts images only. Start a video brief to use footage or audio.');
                    abort_if(count(array_filter($files,fn($f)=>$f['bytes']>10*1024*1024))>0,422,'Image generation accepts source images up to 10 MB.');
                    abort_if(count($files)>4,422,'Use at most four image inputs.');
                    // Reference-only images are inspiration, never model edit inputs.
                    abort_if(count(array_filter($files,fn($f)=>$f['purpose']==='reference'))>0,422,'Describe inspiration in the brief. Image editing only sends images you explicitly allow us to reuse.');
                }
                $mediaInput=($paid && ($settings['output_kind']??'video')==='image') ? app(\App\Services\Generation\Image\NanoBananaImageAdapter::class)->buildInput(end($messages)->content,$settings['aspect_ratio'],['allow_text'=>true]) : null;
                if($paid && ($settings['video_mode']??'composition')==='animate_image') {
                    abort_unless(count($files)===1 && $files[0]['asset_type']==='image' && $files[0]['purpose']==='source' && $files[0]['bytes']<=10*1024*1024,422,'Animation needs exactly one reusable image up to 10 MB.');
                    [,,$mediaInput]=app(\App\Services\Generation\Video\ReplicateI2VAdapter::class)->buildRequestForTier('quick','pending-private-input',end($messages)->content,$settings['duration_seconds'],['resolution'=>'480p']);
                    $mediaInput['enable_prompt_expansion']=false;
                }
                // Plan items the app buys before the build, each at its catalogue price, all under this one approval.
                $plan = PlanService::forQuote($c);
                \App\Services\Create\Planning\PlannerReferenceInspector::verifyEvidence($plan ?? [], $files);
                $planMedia = $paid && ($settings['output_kind'] ?? 'video') === 'video' && ($settings['video_mode'] ?? 'composition') === 'composition' && $plan
                    ? collect($plan['media'] ?? [])->filter(fn ($m) => in_array($m['kind'] ?? '', PlanMediaExecutor::KINDS, true))
                        ->map(function ($m, $i) use ($settings, $user, $plan) {
                            $item = ['id' => $m['id'] ?? null, 'requirement_ids' => $m['requirement_ids'] ?? [], 'requirements' => $m['requirements'] ?? [], 'plan_item_index' => $i, 'kind' => $m['kind'], 'description' => (string) $m['description'], 'subject' => $m['subject'] ?? 'source',
                                ...($m['kind'] === 'character_poses' ? ['character_contract' => CharacterApproval::CONTRACT] : []),
                                'credits' => $m['kind'] === 'music' ? CapabilityCatalogue::musicCredits((int) ($settings['duration_seconds'] ?? 15)) : (int) (CapabilityCatalogue::credits($m['kind'], (int) $user->workspace_id) ?? 0)];
                            return in_array($m['kind'], ['talking_shot', 'talking_take'], true)
                                ? array_merge($item, TalkingPresenter::route($m['kind'], $plan['voice'] ?? null, (int) ($settings['duration_seconds'] ?? 15))) : $item;
                        })->values()->all()
                    : [];
                if (collect($planMedia)->contains(fn ($m) => in_array($m['kind'], ['talking_shot', 'talking_take'], true))) {
                    abort_unless(! empty($plan['narration']) && collect($planMedia)->contains('kind', 'character_poses'),
                        422, 'The talking presenter needs character poses and a script in this plan. Plan again before building.');
                    if (($plan['voice'] ?? null) === 'clone') abort_unless(collect($planMedia)->contains('kind', 'cloned_voiceover'),
                        422, 'The cloned presenter needs a cloned voiceover in the plan.');
                }
                if (collect($planMedia)->contains(fn ($m) => ($m['speech_mode'] ?? '') === 'native')) {
                    abort_unless(in_array($settings['aspect_ratio'], ['9:16', '16:9'], true), 422, 'Native talking video currently supports 9:16 or 16:9.');
                }
                // A character build needs room for the scored review to converge: 20 calls (owner, 2026-10-01).
                if ($paid && isset($policy['agent']) && collect($planMedia)->contains(fn ($m) => in_array($m['kind'], ['character_poses', 'talking_shot'], true))) $policy['agent']['max_calls'] = 20;
                // Design first: the look run builds one still per beat (cheap: 8 calls) for approval; approving it builds the motion from those stills.
                $baseMeta = $base ? (json_decode((string) $base->metadata_json, true) ?: []) : [];
                // Stage is explicit and frozen in the quote. Chat wording never authorizes a transition.
                $stage = $buildStage ?? (! empty($plan['look_first']) ? 'storyboard' : 'full_video');
                $lookFirst = $paid && isset($policy['agent']) && ($settings['output_kind'] ?? 'video') === 'video'
                    && ($settings['video_mode'] ?? 'composition') === 'composition' && $stage === 'storyboard';
                $fromLook = ! empty($baseMeta['look']) && ! $lookFirst;
                if ($paid && ! $lookFirst && $plan && ($settings['output_kind'] ?? 'video') === 'video' && ($settings['video_mode'] ?? 'composition') === 'composition') CharacterPerformance::assertReady($plan, $settings, $planMedia);
                if (! $lookFirst && collect($planMedia)->contains(fn ($m) => in_array($m['kind'], ['character_poses', 'talking_shot', 'talking_take'], true) || ($m['kind'] === 'animate_image' && ($m['subject'] ?? '') === 'approved_character'))) {
                    $approved = CharacterApproval::requireApproved($plan, $settings, (int) $user->workspace_id);
                    foreach ($planMedia as &$motionItem) {
                        if ($motionItem['kind'] === 'animate_image' && ($motionItem['subject'] ?? '') === 'approved_character') $motionItem['master_sha256'] = $approved['files'][0]['sha256'];
                    }
                    unset($motionItem);
                    $master = collect($planMedia)->firstWhere('kind', 'character_poses');
                    if ($master) {
                        // A separate, deterministic cache slot keeps the approved master immutable.
                        $planMedia[] = ['id' => ($master['id'] ?? 'character').'-variants', 'requirement_ids' => $master['requirement_ids'] ?? [], 'requirements' => $master['requirements'] ?? [], 'plan_item_index' => 1000000 + $master['plan_item_index'], 'kind' => 'character_variants',
                            'description' => $master['description'], 'master_sha256' => $approved['files'][0]['sha256'],
                            'credits' => count(PlanMediaExecutor::requestedPoses($master['description'])) * CapabilityCatalogue::CHARACTER_VARIANT_CREDITS];
                    }
                }
                // Superseded generated character assets must not leak into a rebuilt storyboard.
                // A new approved design starts from the brief, not source code still pointing at old poses.
                if ($lookFirst && collect($planMedia)->contains('kind', 'character_poses') && ! CharacterApproval::candidate($plan, $settings, (int) $user->workspace_id)) {
                    $oldIds = DB::table('create_plan_media')->where('conversation_id', $id)->whereIn('kind', ['character_poses', 'character_variants'])->get()->flatMap(function ($row) {
                        $r = json_decode($row->record_json ?? '{}', true);
                        return array_column(array_filter([$r['file'] ?? null, ...($r['more_files'] ?? [])]), 'asset_id');
                    })->all();
                    $files = array_values(array_filter($files, fn ($f) => ! in_array($f['asset_id'], $oldIds, true)));
                    $base = null;
                }
                if ($lookFirst) {
                    $planMedia = array_values(array_filter($planMedia, fn ($m) => ! in_array($m['kind'], PlanMediaService::PRODUCTION_ONLY, true)));
                }
                foreach ($planMedia as &$mediaItem) {
                    if (in_array($mediaItem['kind'], ['talking_shot', 'talking_take'], true)) continue;
                    $context = ['narration' => $plan['narration'] ?? [], 'voice' => $plan['voice'] ?? null, 'aspect_ratio' => $settings['aspect_ratio'], 'character_style' => $plan['character_style'] ?? ''];
                    if ($mediaItem['kind'] === 'voiceover' && collect($planMedia)->contains(fn ($m) => $m['kind'] === 'talking_shot' && ($m['speech_mode'] ?? '') === 'native')) $context['narration'] = array_slice($context['narration'], 1);
                    $hash = CharacterApproval::mediaHash($mediaItem, $context);
                    $cached = DB::table('create_plan_media')->where('plan_id', $plan['plan_id'])->where('item_index', $mediaItem['plan_item_index'])->where('status', 'succeeded')->where('description_hash', $hash)->first();
                    if ($cached) { $mediaItem['reuse_media_id'] = $cached->id; $mediaItem['credits'] = 0; }
                }
                unset($mediaItem);
                if ($lookFirst) { $policy['agent']['max_calls'] = min($policy['agent']['max_calls'], 8); if (isset($policy['critic'])) $policy['critic']['max_calls'] = 1; }
                $resolvedPack = StylePacks::resolve($plan['style_route'] ?? null, (int) $user->workspace_id, $settings,
                    DB::table('create_attachments')->where('conversation_id',$id)->where('purpose','reference')->orderBy('asset_id')->pluck('asset_id')->map(fn($a)=>(int)$a)->all());
                // Media is approved as a ceiling, not an item list: the plan's items are the estimate; the agent may buy
                // more under the ceiling (1.5x the estimate, or the conversation's own figure) and must ask above it.
                $mediaEstimate = array_sum(array_column($planMedia, 'credits'));
                $mediaCeiling = $paid && ($settings['output_kind'] ?? 'video') === 'video' && ($settings['video_mode'] ?? 'composition') === 'composition'
                    ? max($mediaEstimate, isset($settings['media_ceiling_credits']) ? (int) $settings['media_ceiling_credits'] : (int) ceil($mediaEstimate * 1.5)) : 0;
                if ($mediaCeiling > 0) {
                    $top = max(array_merge([0], array_column($planMedia, 'credits'), array_map(fn ($t) => (int) $t['credits'], array_filter(CapabilityCatalogue::forWorkspace((int) $user->workspace_id), fn ($t) => in_array($t['kind'], PlanMediaExecutor::KINDS, true)))));
                    $policy['plan_media'] = ['provider' => 'wyvstudio', 'model' => 'catalogue-2026-10', 'credits' => $top, 'cost_limit_microusd' => $top * 4000,
                        'max_calls' => count($planMedia) + 6, 'total_credits' => $mediaCeiling];
                }
                $payload = ['kind' => 'composition_fixture', 'conversation_id' => $id, 'version' => $version,
                    'base_revision_id' => $c->head_revision_id, 'messages' => $messages, 'attachments' => DB::table('create_attachments')->where('conversation_id',$id)->orderBy('asset_id')->get(['asset_id','purpose'])->all(),
                    'execution_policy' => $policy, 'pilot_budget_id'=>$paid ? config('create.pilot_budget_id') : null,
                    'input_files' => $files, 'base_bundle' => $base ? json_decode($base->bundle_json, true) : null,
                    'base_bundle_hash' => $base?->bundle_hash,
                    'base_review' => $baseMeta['creative_review'] ?? null,
                    'media_input'=>$mediaInput,
                    'plan'=>$plan,
                    'plan_media'=>$planMedia,
                    'style'=>StyleService::brief($settings['style_id'] ?? null, (int) $user->workspace_id),
                    'build_stage'=>$lookFirst ? 'storyboard' : 'full_video', 'look_first'=>$lookFirst, 'from_look'=>$fromLook, 'media_estimate'=>$mediaEstimate, 'media_ceiling'=>$mediaCeiling,
                    'style_notes'=>app(StyleNotes::class)->for((int) $user->workspace_id, StyleNotes::keyFor(['style_pack'=>$resolvedPack, 'settings'=>$settings])),
                    // The craft the build starts from, frozen here so later edits to a pack never change this run.
                    'style_pack'=>$resolvedPack,
                    'settings' => $settings, 'mode' => $paid ? 'agent' : 'fixture'];
                return ApiQuote::create(['id' => ApiQuote::newId(), 'workspace_id' => $user->workspace_id,
                    'created_by_user_id' => $user->id, 'payload_json' => $payload, 'credits_min' => 0, 'credits_max' => array_sum(array_map(fn($p)=>$p['total_credits'] ?? $p['credits']*$p['max_calls'],$policy)),
                    'expires_at' => now()->addMinutes(10)]);
            });
        } catch (\Throwable $e) {
            $snapshots->discard($newFiles);
            throw $e;
        }
    }

    /** Small jobs run without a separate approval, once the user has consented to the provider here. */
    public function autoRunEligible(User $user, object $c, ApiQuote $quote): bool
    {
        $p = $quote->payload_json;
        if (($p['kind'] ?? '') !== 'composition_fixture' || ! empty($p['free_edit'])) return false;
        if ((int) $quote->credits_max > (int) config('create.auto_run_credits', 15)) return false;
        if ($p['mode'] === 'agent' && ! $c->provider_consent_at) return false;
        $today = DB::table('composition_runs')->where('workspace_id', $user->workspace_id)->where('created_at', '>=', now()->startOfDay())->whereNotNull('input_json->auto_run')->count();
        return $today < (int) config('create.auto_run_daily_limit', 20);
    }

    public function approve(User $user, string $id, string $quoteId, string $key, bool $providerApproved = false, bool $auto = false): object
    {
        $this->authorize($user, true);
        abort_unless(OperationAccounting::enabled(), 503, 'Shared operation accounting must be enabled for local integration testing.');
        $operation = null;
        try {
            return DB::transaction(function () use ($user, $id, $quoteId, $key, $providerApproved, $auto, &$operation) {
                // Same sorted pool/spender lock order as CreditService. No provider work under these locks.
                $workspace = Workspace::findOrFail($user->workspace_id);
                Workspace::whereIn('id', array_unique([$workspace->id, $workspace->parent_workspace_id ?: $workspace->id]))->orderBy('id')->lockForUpdate()->get();
                $c = $this->conversation($user, $id, true);
                $hash = hash('sha256', $id.'|'.$quoteId);
                $old = DB::table('composition_runs')->where('workspace_id', $user->workspace_id)->where('idempotency_key', $key)->first();
                if ($old) {
                    abort_unless(hash_equals($old->request_hash, $hash), 409, 'Request key already used for another approval.');
                    return $old;
                }
                abort_if($c->archived_at, 409, 'Conversation is archived.');
                $quote = ApiQuote::where('workspace_id', $user->workspace_id)->whereKey($quoteId)->lockForUpdate()->firstOrFail();
                $p = $quote->payload_json;
                abort_unless(($p['kind'] ?? '') === 'composition_fixture' && ($p['conversation_id'] ?? '') === $id, 422, 'Wrong quote.');
                abort_if($quote->isExpired() || $quote->consumed_at, 409, 'This quote expired or was already used.');
                abort_unless((int) $c->version === $p['version'] && $c->head_revision_id === $p['base_revision_id'], 409, 'The brief changed. Review a new quote.');
                abort_unless($p['mode']===config('create.mode') && ($p['mode']==='fixture' || PilotPolicy::enabled()),503);
                if($auto) { abort_unless($this->autoRunEligible($user,$c,$quote),409,'This job needs your approval.'); $p['auto_run']=true; $providerApproved=$providerApproved || (bool)$c->provider_consent_at; }
                if (($p['build_stage'] ?? '') === 'full_video' && ! empty($p['plan']['character_performance'])) CharacterPerformance::assertReady($p['plan'], $p['settings'], $p['plan_media'] ?? []);
                if (($p['build_stage'] ?? '') === 'full_video' && collect($p['plan_media'] ?? [])->contains(fn ($m) => in_array($m['kind'], ['character_poses', 'character_variants', 'talking_shot', 'talking_take'], true) || ($m['kind'] === 'animate_image' && ($m['subject'] ?? '') === 'approved_character'))) CharacterApproval::requireApproved($p['plan'], $p['settings'], (int) $user->workspace_id);
                $free=!empty($p['free_edit']);
                if($p['mode']==='agent' && $free) { abort_unless(($p['execution_policy']['render']['credits']??1)===0 && count($p['execution_policy'])===1,422,'Invalid free edit.'); }
                elseif($p['mode']==='agent') { abort_unless($providerApproved,422,'Confirm sending this brief and approved media to our AI providers.'); abort_unless(($p['pilot_budget_id']??null)===config('create.pilot_budget_id'),409,'Pilot approval changed. Get a fresh quote.'); PilotPolicy::admit($p['execution_policy']); }
                abort_if(DB::table('composition_runs')->where('workspace_id', $user->workspace_id)->where(fn ($q) => $q->whereIn('status', ['queued', 'running', 'cancel_requested'])->orWhere(fn ($q) => $q->where('status', 'needs_attention')->where(fn ($q) => $q->where('conversation_id', $id)->orWhereNull('worker_stopped_at'))))->when($p['variant_group']??null,fn($q,$group)=>$q->where(fn($q)=>$q->whereNull('input_json->variant_group')->orWhere('input_json->variant_group','!=',$group)))->exists(), 409, 'Another creation is active or awaiting recovery.');
                abort_if(! $free && DB::table('composition_runs')->where('workspace_id', $user->workspace_id)->where('created_at', '>=', now()->startOfDay())->whereNull('input_json->free_edit')->count() >= (int) config('create.run_daily_limit', 10), 429, 'Local pilot daily limit reached.');
                if($p['mode']==='agent' && $providerApproved && ! $c->provider_consent_at) DB::table('create_conversations')->where('id',$id)->update(['provider_consent_at'=>now()]);
                foreach ($p['input_files'] ?? [] as $file) {
                    abort_unless(Asset::where('workspace_id', $user->workspace_id)->whereKey($file['asset_id'])->where('status', '!=', 'archived')->exists(), 409, 'An attached asset is no longer available.');
                }
                if(isset($p['retry_of'])) {
                    abort_if(DB::table('composition_runs')->where('input_json->retry_of',$p['retry_of'])->exists(),409,'Retry already approved.');
                    abort_unless(DB::table('composition_runs')->where('id',$p['retry_of'])->where('workspace_id',$user->workspace_id)->where('status','failed')->exists() && !AttemptService::unresolved($p['retry_of']),409,'Original outcome is not retryable.');
                }
                try {
                    $operation = OperationAccounting::reserve($quote, null);
                } catch (\DomainException $e) {
                    // Expected admission failures must not become a generic HTTP 500.
                    if ($e->getMessage() === 'insufficient_credits') {
                        $credits = $this->creditAvailability($user);
                        $required = (int) $quote->credits_max;
                        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                            'message' => sprintf('This creation needs %s available credits. You have %s available (%s total; %s reserved for unfinished work).',
                                number_format($required), number_format($credits['available']), number_format($credits['total']), number_format($credits['reserved'])),
                            'code' => 'insufficient_credits', 'credit_availability' => $credits,
                            'required_credits' => $required, 'shortfall' => max(0, $required - $credits['available']),
                        ], 402));
                    }
                    match ($e->getMessage()) {
                        'too_many_active_videos' => abort(429, 'This workspace has reached its simultaneous video limit. Wait for active work to finish or resolve paused work, then try again.'),
                        'operation_busy' => abort(409, 'This operation is busy. Wait a moment, then try again.'),
                        default => throw $e,
                    };
                }
                $runId = (string) Str::uuid();
                DB::table('composition_runs')->insert(['id' => $runId, 'conversation_id' => $id, 'workspace_id' => $user->workspace_id,
                    'quote_id' => $quoteId, 'operation_id' => $operation, 'idempotency_key' => $key, 'request_hash' => $hash,
                    'input_json' => json_encode($p), 'status' => 'queued', 'stage' => $p['mode']==='fixture' ? 'Queued for local fixture render' : 'Queued for your creation', 'created_at' => now(), 'updated_at' => now()]);
                $quote->update(['consumed_at' => now(), 'idempotency_key' => 'create:'.$runId]);
                return DB::table('composition_runs')->where('id', $runId)->first();
            });
        } finally {
            if ($operation) OperationFence::release($operation);
            Context::forgetHidden(OperationAccounting::CONTEXT);
        }
    }

    public function restore(User $user, string $id, string $revisionId, int $version): string
    {
        $this->authorize($user, true);
        return DB::transaction(function () use ($user, $id, $revisionId, $version) {
            $c = $this->conversation($user, $id, true);
            abort_if($c->archived_at || (int) $c->version !== $version, 409, 'Conversation changed.');
            $old = DB::table('composition_revisions')->where('conversation_id', $id)->where('id', $revisionId)->firstOrFail();
            $new = (string) Str::uuid();
            DB::table('composition_revisions')->insert([
                'id' => $new, 'conversation_id' => $id,
                'number' => 1 + (int) DB::table('composition_revisions')->where('conversation_id', $id)->max('number'),
                'parent_revision_id' => $c->head_revision_id, 'restored_from_id' => $old->id,
                'bundle_json' => $old->bundle_json, 'bundle_hash' => $old->bundle_hash, 'artifact_path' => $old->artifact_path,
                'artifact_hash' => $old->artifact_hash, 'metadata_json'=>$old->metadata_json??null, 'summary' => 'Restored earlier version', 'created_at' => now(),
            ]);
            DB::table('create_conversations')->where('id', $id)->update(['head_revision_id' => $new, 'version' => $c->version + 1, 'updated_at' => now()]);
            return $new;
        });
    }
}

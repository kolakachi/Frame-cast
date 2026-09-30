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

    public function quote(User $user, string $id, int $version): ApiQuote
    {
        $this->authorize($user, true);
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
            return DB::transaction(function () use ($user, $id, $version, $files, $attachments) {
                $c = $this->conversation($user, $id, true);
                abort_if($c->archived_at || (int) $c->version !== $version, 409, 'Conversation changed. Review a fresh plan.');
                $messages = DB::table('create_messages')->where('conversation_id', $id)->orderBy('sequence')->get(['role', 'content'])->all();
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
                $planMedia = $paid && ($settings['output_kind'] ?? 'video') === 'video' && ($settings['video_mode'] ?? 'composition') === 'composition' && $plan
                    ? collect($plan['media'] ?? [])->filter(fn ($m) => in_array($m['kind'] ?? '', PlanMediaExecutor::KINDS, true))->take(6)
                        ->map(fn ($m) => ['kind' => $m['kind'], 'description' => (string) $m['description'], 'credits' => (int) (CapabilityCatalogue::credits($m['kind'], (int) $user->workspace_id) ?? 0)])->values()->all()
                    : [];
                if ($planMedia) {
                    $top = max(array_column($planMedia, 'credits'));
                    $policy['plan_media'] = ['provider' => 'wyvstudio', 'model' => 'catalogue-2026-10', 'credits' => $top, 'cost_limit_microusd' => $top * 4000,
                        'max_calls' => count($planMedia), 'total_credits' => array_sum(array_column($planMedia, 'credits'))];
                }
                $payload = ['kind' => 'composition_fixture', 'conversation_id' => $id, 'version' => $version,
                    'base_revision_id' => $c->head_revision_id, 'messages' => $messages, 'attachments' => DB::table('create_attachments')->where('conversation_id',$id)->orderBy('asset_id')->get(['asset_id','purpose'])->all(),
                    'execution_policy' => $policy, 'pilot_budget_id'=>$paid ? config('create.pilot_budget_id') : null,
                    'input_files' => $files, 'base_bundle' => $base ? json_decode($base->bundle_json, true) : null,
                    'base_bundle_hash' => $base?->bundle_hash,
                    'media_input'=>$mediaInput,
                    'plan'=>$plan,
                    'plan_media'=>$planMedia,
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
                $free=!empty($p['free_edit']);
                if($p['mode']==='agent' && $free) { abort_unless(($p['execution_policy']['render']['credits']??1)===0 && count($p['execution_policy'])===1,422,'Invalid free edit.'); }
                elseif($p['mode']==='agent') { abort_unless($providerApproved,422,'Confirm sending this brief and approved media to our AI providers.'); abort_unless(($p['pilot_budget_id']??null)===config('create.pilot_budget_id'),409,'Pilot approval changed. Get a fresh quote.'); PilotPolicy::admit($p['execution_policy']); }
                abort_if(DB::table('composition_runs')->where('workspace_id', $user->workspace_id)->whereIn('status', self::ACTIVE)->when($p['variant_group']??null,fn($q,$group)=>$q->where(fn($q)=>$q->whereNull('input_json->variant_group')->orWhere('input_json->variant_group','!=',$group)))->exists(), 409, 'Another creation is active or awaiting recovery.');
                abort_if(! $free && DB::table('composition_runs')->where('workspace_id', $user->workspace_id)->where('created_at', '>=', now()->startOfDay())->whereNull('input_json->free_edit')->count() >= 10, 429, 'Local pilot daily limit reached.');
                if($p['mode']==='agent' && $providerApproved && ! $c->provider_consent_at) DB::table('create_conversations')->where('id',$id)->update(['provider_consent_at'=>now()]);
                foreach ($p['input_files'] ?? [] as $file) {
                    abort_unless(Asset::where('workspace_id', $user->workspace_id)->whereKey($file['asset_id'])->where('status', '!=', 'archived')->exists(), 409, 'An attached asset is no longer available.');
                }
                if(isset($p['retry_of'])) {
                    abort_if(DB::table('composition_runs')->where('input_json->retry_of',$p['retry_of'])->exists(),409,'Retry already approved.');
                    abort_unless(DB::table('composition_runs')->where('id',$p['retry_of'])->where('workspace_id',$user->workspace_id)->where('status','failed')->exists() && !AttemptService::unresolved($p['retry_of']),409,'Original outcome is not retryable.');
                }
                $operation = OperationAccounting::reserve($quote, null);
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

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
        $id = (string) Str::uuid();
        DB::table('create_conversations')->insert([
            'id' => $id, 'workspace_id' => $user->workspace_id, 'created_by_user_id' => $user->id,
            'title' => 'New creation', 'settings_json' => json_encode($settings), 'created_at' => now(), 'updated_at' => now(),
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
            DB::table('create_messages')->insert([
                'id' => $messageId, 'conversation_id' => $id, 'role' => 'user', 'content' => $input['content'],
                'idempotency_key' => $input['idempotency_key'], 'request_hash' => $hash, 'sequence' => $c->version + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('create_conversations')->where('id', $id)->update([
                'version' => $c->version + 1, 'updated_at' => now(),
                'title' => $c->title === 'New creation' ? Str::limit($input['content'], 80, '') : $c->title,
            ]);
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
        abort_unless(config('create.mode') === 'fixture', 503, 'Paid generation is awaiting accounting and model acceptance.');
        return DB::transaction(function () use ($user, $id, $version) {
            $c = $this->conversation($user, $id, true);
            abort_if($c->archived_at || (int) $c->version !== $version, 409, 'Conversation changed. Review a fresh plan.');
            $messages = DB::table('create_messages')->where('conversation_id', $id)->orderBy('sequence')->get(['role', 'content'])->all();
            abort_if(! count($messages), 422, 'Add a brief first.');
            $attachments = DB::table('create_attachments')->where('conversation_id', $id)->orderBy('id')->get(['asset_id', 'purpose'])->all();
            $payload = ['kind' => 'composition_fixture', 'conversation_id' => $id, 'version' => $version,
                'base_revision_id' => $c->head_revision_id, 'messages' => $messages, 'attachments' => $attachments,
                'settings' => json_decode($c->settings_json, true), 'mode' => 'fixture'];
            return ApiQuote::create(['id' => ApiQuote::newId(), 'workspace_id' => $user->workspace_id,
                'created_by_user_id' => $user->id, 'payload_json' => $payload, 'credits_min' => 0, 'credits_max' => 0,
                'expires_at' => now()->addMinutes(10)]);
        });
    }

    public function approve(User $user, string $id, string $quoteId, string $key): object
    {
        $this->authorize($user, true);
        abort_unless(OperationAccounting::enabled(), 503, 'Shared operation accounting must be enabled for local integration testing.');
        $operation = null;
        try {
            return DB::transaction(function () use ($user, $id, $quoteId, $key, &$operation) {
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
                abort_unless(config('create.mode') === 'fixture' && $quote->credits_max === 0, 503);
                abort_if(DB::table('composition_runs')->where('workspace_id', $user->workspace_id)->whereIn('status', self::ACTIVE)->exists(), 409, 'Another creation is active or awaiting recovery.');
                abort_if(DB::table('composition_runs')->where('workspace_id', $user->workspace_id)->where('created_at', '>=', now()->startOfDay())->count() >= 10, 429, 'Local pilot daily limit reached.');
                $operation = OperationAccounting::reserve($quote, null);
                $runId = (string) Str::uuid();
                DB::table('composition_runs')->insert(['id' => $runId, 'conversation_id' => $id, 'workspace_id' => $user->workspace_id,
                    'quote_id' => $quoteId, 'operation_id' => $operation, 'idempotency_key' => $key, 'request_hash' => $hash,
                    'input_json' => json_encode($p), 'status' => 'queued', 'stage' => 'Queued for local fixture render', 'created_at' => now(), 'updated_at' => now()]);
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
                'artifact_hash' => $old->artifact_hash, 'summary' => 'Restored earlier version', 'created_at' => now(),
            ]);
            DB::table('create_conversations')->where('id', $id)->update(['head_revision_id' => $new, 'version' => $c->version + 1, 'updated_at' => now()]);
            return $new;
        });
    }
}

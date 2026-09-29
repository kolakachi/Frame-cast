<?php
namespace App\Services\Create;

use App\Models\{ApiQuote, User};
use Illuminate\Support\Facades\DB;

/**
 * Text and colour changes to a finished version. The new values are baked into
 * the composition's variables and the bundle is re-rendered as is: no model
 * call, no credits, one render. Always makes a new version on top of the
 * current one, so an older version can be the starting point without being
 * overwritten.
 */
class FreeEditService
{
    public function __construct(private ConversationService $conversations) {}

    public function apply(User $user, string $id, string $revisionId, array $values, int $version, string $key): object
    {
        $this->conversations->authorize($user, true);
        $existing = DB::table('composition_runs')->where('workspace_id', $user->workspace_id)->where('idempotency_key', $key)->first();
        if ($existing) return $existing;
        $c = $this->conversations->conversation($user, $id);
        abort_if($c->archived_at, 409, 'Restore this conversation before editing.');
        abort_unless((int) $c->version === $version, 409, 'Conversation changed. Refresh before editing.');
        $revision = DB::table('composition_revisions')->where('conversation_id', $id)->where('id', $revisionId)->firstOrFail();
        $bundle = json_decode($revision->bundle_json, true);
        $declarations = CompositionVariables::declarations($bundle['index.html'] ?? null);
        abort_if(! $declarations, 422, 'This version has no editable text or colours. Ask the assistant for the change instead.');
        $changes = CompositionVariables::validate($declarations, $values);
        abort_if(! $changes, 422, 'Nothing changed.');
        $today = DB::table('composition_runs')->where('workspace_id', $user->workspace_id)->where('created_at', '>=', now()->startOfDay())
            ->whereNotNull('input_json->free_edit')->count();
        abort_if($today >= (int) config('create.free_edit_daily_limit', 60), 429, 'Today\'s free edits are used up. They reset at midnight.');

        $bundle['index.html'] = CompositionVariables::apply($bundle['index.html'], $declarations, $changes);
        $metadata = json_decode($revision->metadata_json ?? '{}', true);
        $snapshots = app(InputSnapshotService::class);
        $files = $snapshots->inherited($id, $revisionId);
        $snapshots->verify($files);
        $quote = ApiQuote::create(['id' => ApiQuote::newId(), 'workspace_id' => $user->workspace_id, 'created_by_user_id' => $user->id, 'credits_min' => 0, 'credits_max' => 0,
            'expires_at' => now()->addMinutes(10), 'payload_json' => [
                'kind' => 'composition_fixture', 'conversation_id' => $id, 'version' => $version, 'base_revision_id' => $c->head_revision_id,
                'source_revision_id' => $revisionId, 'free_edit' => true, 'edit_values' => $changes,
                'messages' => DB::table('create_messages')->where('conversation_id', $id)->orderBy('sequence')->get(['role', 'content'])->all(),
                'attachments' => DB::table('create_attachments')->where('conversation_id', $id)->orderBy('asset_id')->get(['asset_id', 'purpose'])->all(),
                'execution_policy' => ['render' => ['provider' => 'offline', 'model' => 'hyperframes-0.8.82', 'credits' => 0, 'cost_limit_microusd' => 0, 'max_calls' => 1]],
                'pilot_budget_id' => config('create.mode') === 'agent' ? config('create.pilot_budget_id') : null,
                'input_files' => $files, 'base_bundle' => $bundle, 'base_bundle_hash' => hash('sha256', json_encode($bundle)), 'media_input' => null, 'plan' => null,
                'settings' => $metadata['settings'] ?? json_decode($c->settings_json, true), 'mode' => config('create.mode'),
            ]]);
        return $this->conversations->approve($user, $id, $quote->id, $key, false, false);
    }
}

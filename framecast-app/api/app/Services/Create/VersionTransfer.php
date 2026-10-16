<?php

namespace App\Services\Create;

use App\Models\{ApiQuote, Asset, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves a finished Create version between installs (TPL-LOCAL, 2026-10-16: eleven examples were built locally, so on
 * prod they were videos only and "Exactly" could not start from their code). export() writes a folder: the version's
 * code, the files its code uses, what each bought file was, its plan and its video. import() makes it a conversation in
 * staff's workspace (new assets, renamed in the code) and, given a template, makes that template start from it.
 */
class VersionTransfer
{
    public function export(string $revisionId, string $dir): array
    {
        $revision = DB::table('composition_revisions')->where('id', $revisionId)->firstOrFail();
        $run = $revision->run_id ? json_decode((string) DB::table('composition_runs')->where('id', $revision->run_id)->value('input_json'), true) : [];
        $bundle = json_decode((string) $revision->bundle_json, true) ?: [];
        $code = implode("\n", array_filter($bundle, 'is_string'));
        $storage = app(CreateStorage::class);
        @mkdir($dir.'/files', 0755, true);
        $files = [];
        foreach (app(InputSnapshotService::class)->inherited($revision->conversation_id, $revision->id) as $f) {
            if (! str_contains($code, (string) $f['name'])) continue;
            $bytes = $storage->get($f['storage_path']);
            abort_unless(is_string($bytes) && hash('sha256', $bytes) === $f['sha256'], 409, 'A file of this version could not be read: '.$f['name']);
            file_put_contents($dir.'/files/'.$f['name'], $bytes);
            $files[] = array_intersect_key($f, array_flip(['asset_id', 'name', 'sha256', 'bytes', 'mime_type', 'asset_type', 'duration_seconds', 'title', 'operation', 'derived_from_asset_id']));
        }
        // What each bought file was, so the parts list can say what a picture shows.
        $bought = [];
        foreach (DB::table('create_plan_media')->where('conversation_id', $revision->conversation_id)->where('status', 'succeeded')->get() as $m) {
            $r = json_decode((string) $m->record_json, true) ?: [];
            if ($id = (int) data_get($r, 'file.asset_id')) $bought[] = ['asset_id' => $id, 'kind' => $m->kind, 'description' => (string) ($r['description'] ?? ''), 'credits' => (int) $m->charged_credits];
        }
        if ($revision->artifact_path && $storage->exists($revision->artifact_path)) file_put_contents($dir.'/video.mp4', $storage->get($revision->artifact_path));
        $metadata = json_decode((string) ($revision->metadata_json ?? '{}'), true) ?: [];
        unset($metadata['requirements'], $metadata['requirement_history']);
        $manifest = ['revision_id' => $revision->id, 'title' => (string) DB::table('create_conversations')->where('id', $revision->conversation_id)->value('title'),
            'bundle' => $bundle, 'metadata' => $metadata, 'summary' => $revision->summary, 'artifact_hash' => $revision->artifact_hash,
            'input' => array_intersect_key($run, array_flip(['plan', 'plan_media', 'style', 'style_pack', 'settings'])), 'files' => $files, 'bought' => $bought];
        file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return ['files' => count($files), 'bytes' => array_sum(array_column($files, 'bytes')), 'video' => is_file($dir.'/video.mp4')];
    }

    public function import(string $dir, User $user, ?string $templateId = null): array
    {
        abort_unless(TemplateLibrary::isStaff($user), 403);
        $m = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
        abort_unless(is_array($m) && isset($m['bundle']), 422, 'Not an exported version.');
        $storage = app(CreateStorage::class);
        $c = app(ConversationService::class)->create($user, OutputSettings::normalize(array_intersect_key((array) ($m['input']['settings'] ?? []), OutputSettings::rules())));
        $conversationId = $c->id;
        DB::table('create_conversations')->where('id', $conversationId)->update(['title' => mb_substr('Template source: '.$m['title'], 0, 120)]);
        $bundle = $m['bundle']; $copied = []; $ids = [];
        foreach ($m['files'] as $f) {
            $bytes = file_get_contents($dir.'/files/'.$f['name']);
            abort_unless(is_string($bytes) && hash('sha256', $bytes) === $f['sha256'], 409, 'A file changed in transit: '.$f['name']);
            $ext = pathinfo((string) $f['name'], PATHINFO_EXTENSION);
            $suffix = $user->workspace_id.'/'.Str::uuid().'/'.$f['sha256'].'.'.$ext;
            abort_unless($storage->put('create/uploads/'.$suffix, $bytes, ['visibility' => 'private']), 503, 'Could not store '.$f['name']);
            $asset = Asset::create(['workspace_id' => $user->workspace_id, 'created_by_user_id' => $user->id, 'asset_type' => $f['asset_type'],
                'title' => mb_substr((string) ($f['title'] ?? 'Template media'), 0, 180), 'storage_url' => 'create-upload://'.$suffix, 'mime_type' => $f['mime_type'],
                'file_size_bytes' => (int) $f['bytes'], 'duration_seconds' => $f['duration_seconds'] ?? null, 'status' => 'active', 'restriction_scope' => 'workspace',
                'metadata_json' => ['imported_from_revision' => $m['revision_id'], 'imported_asset_id' => (int) $f['asset_id'], 'create_conversation_id' => $conversationId]]);
            $ids[(int) $f['asset_id']] = (int) $asset->id;
            $name = 'asset-'.$asset->id.'-'.$f['sha256'].'.'.$ext;
            $copied[] = ['asset_id' => (int) $asset->id, 'purpose' => 'source', 'name' => $name, 'sha256' => $f['sha256'], 'bytes' => (int) $f['bytes'],
                'mime_type' => $f['mime_type'], 'asset_type' => $f['asset_type'], 'storage_path' => 'create/uploads/'.$suffix, 'duration_seconds' => $f['duration_seconds'] ?? null,
                'transcript' => '', 'title' => $f['title'] ?? null];
            foreach ($bundle as $file => $text) if (is_string($text)) $bundle[$file] = str_replace((string) $f['name'], $name, $text);
        }
        $runId = (string) Str::uuid();
        $artifact = null;
        if (is_file($dir.'/video.mp4')) {
            $artifact = 'create/previews/'.$runId.'/'.($m['artifact_hash'] ?: hash_file('sha256', $dir.'/video.mp4')).'.mp4';
            abort_unless($storage->put($artifact, file_get_contents($dir.'/video.mp4'), ['visibility' => 'private']), 503, 'Could not store the video.');
        }
        $revisionId = (string) Str::uuid();
        $planId = (string) Str::uuid();
        DB::transaction(function () use ($user, $conversationId, $m, $copied, $bundle, $runId, $revisionId, $artifact, $ids, $planId) {
            $quote = ApiQuote::create(['id' => ApiQuote::newId(), 'workspace_id' => $user->workspace_id, 'created_by_user_id' => $user->id,
                'credits_min' => 0, 'credits_max' => 0, 'expires_at' => now(), 'consumed_at' => now(), 'payload_json' => ['kind' => 'version_import', 'from' => $m['revision_id']]]);
            $input = ['kind' => 'version_import', 'conversation_id' => $conversationId, 'imported_from_revision' => $m['revision_id'], 'input_files' => $copied, 'derived_files' => [],
                ...array_intersect_key((array) ($m['input'] ?? []), array_flip(['plan', 'plan_media', 'style', 'style_pack', 'settings'])), 'mode' => config('create.mode')];
            DB::table('composition_runs')->insert(['id' => $runId, 'conversation_id' => $conversationId, 'workspace_id' => $user->workspace_id, 'quote_id' => $quote->id,
                'idempotency_key' => 'import:'.$conversationId, 'request_hash' => hash('sha256', json_encode($input)), 'input_json' => json_encode($input),
                'status' => 'preview_ready', 'stage' => 'Imported from another install', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('composition_revisions')->insert(['id' => $revisionId, 'conversation_id' => $conversationId, 'run_id' => $runId, 'number' => 1,
                'bundle_json' => json_encode($bundle), 'bundle_hash' => hash('sha256', json_encode($bundle)), 'artifact_path' => $artifact, 'artifact_hash' => $artifact ? ($m['artifact_hash'] ?? null) : null,
                'metadata_json' => json_encode($m['metadata'] ?? []), 'summary' => $m['summary'] ?: 'Imported version', 'created_at' => now()]);
            // What each bought file was, under its new asset id (the parts list reads it).
            foreach ($m['bought'] ?? [] as $i => $b) if (isset($ids[$b['asset_id']])) DB::table('create_plan_media')->insert(['id' => (string) Str::uuid(), 'conversation_id' => $conversationId,
                'plan_id' => $planId, 'item_index' => $i, 'kind' => $b['kind'], 'description_hash' => hash('sha256', $b['description']), 'status' => 'succeeded',
                'record_json' => json_encode(['description' => $b['description'], 'file' => ['asset_id' => $ids[$b['asset_id']]]]), 'charged_credits' => (int) $b['credits'], 'created_at' => now(), 'updated_at' => now()]);
            $c = DB::table('create_conversations')->where('id', $conversationId)->lockForUpdate()->first();
            DB::table('create_conversations')->where('id', $conversationId)->update(['head_revision_id' => $revisionId, 'version' => $c->version + 1, 'updated_at' => now()]);
        });
        $linked = $templateId ? $this->link($user, $templateId, $conversationId, $revisionId) : null;
        return ['conversation_id' => $conversationId, 'revision_id' => $revisionId, 'files' => count($copied), 'template' => $linked];
    }

    /** A template starts from this version: Exactly copies its code; the template keeps its public poster and video. */
    public function link(User $user, string $templateId, string $conversationId, string $revisionId): array
    {
        abort_unless(TemplateLibrary::isStaff($user), 403);
        $revision = DB::table('composition_revisions')->where('conversation_id', $conversationId)->where('id', $revisionId)->firstOrFail();
        abort_unless(DB::table('create_conversations')->where('id', $conversationId)->where('workspace_id', $user->workspace_id)->exists(), 404);
        $parts = TemplateLibrary::partsOf($revision);
        $template = DB::table('create_templates')->where('id', $templateId)->first();
        abort_unless($template, 404, 'No such template.');
        DB::table('create_templates')->where('id', $templateId)->update(['source' => 'version', 'conversation_id' => $conversationId, 'revision_id' => $revisionId,
            'parts_json' => json_encode($parts), 'style' => $template->style ?: ($parts['style'] ?? null), 'updated_at' => now()]);
        return ['id' => $templateId, 'summary' => TemplateLibrary::summary($parts)];
    }
}

<?php

namespace Tests\Feature;

use App\Models\{ApiQuote, Asset, User, Workspace};
use App\Services\Create\{ConversationService, RunService};
use Illuminate\Support\Facades\{Bus, DB, Http, Redis};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsDeveloperSchema;
use Tests\TestCase;

class CreateIntegrationTest extends TestCase
{
    use BuildsDeveloperSchema;
    private User $owner;
    private Workspace $workspace;
    private ConversationService $conversations;
    private RunService $runs;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'create_test', 'database.connections.create_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ], 'cache.default' => 'array', 'services.posthog.key' => '', 'create.enabled' => true, 'create.mode' => 'fixture', 'developer.operation_accounting' => true]);
        DB::purge('create_test');
        Bus::fake(); Http::preventStrayRequests(); Redis::shouldReceive('get')->andReturn(null);
        $this->buildDeveloperSchema();
        (require database_path('migrations/2026_09_25_200000_create_api_operations.php'))->up();
        (require database_path('migrations/2026_09_28_120000_create_composition_conversations.php'))->up();
        (require database_path('migrations/2026_09_29_000000_create_composition_attempts.php'))->up();
        $this->workspace = Workspace::create(['name' => 'Local', 'plan_tier' => 'creator', 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => 100]);
        $this->owner = User::create(['email' => 'local@example.test', 'name' => 'Local', 'role' => 'owner', 'status' => 'active']);
        $this->owner->forceFill(['workspace_id' => $this->workspace->id])->save();
        config(['create.workspaces' => [(int) $this->workspace->id]]);
        $this->conversations = app(ConversationService::class); $this->runs = app(RunService::class);
    }

    private function brief(): object
    {
        $c = $this->conversations->create($this->owner, ['duration_seconds' => 15, 'aspect_ratio' => '9:16']);
        $this->conversations->message($this->owner, $c->id, ['content' => 'Keep my source audio.', 'expected_version' => 0, 'idempotency_key' => 'message-1']);
        return $this->conversations->conversation($this->owner, $c->id);
    }

    private function admitted(): array
    {
        $c = $this->brief(); $q = $this->conversations->quote($this->owner, $c->id, 1);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'approve-'.$c->id);
        return [$c, $q, $run];
    }

    private function rejected(int $status, callable $fn): void
    {
        try { $fn(); $this->fail('Expected HTTP '.$status); }
        catch (HttpException $e) { $this->assertSame($status, $e->getStatusCode()); }
    }

    public function test_feature_off_and_non_allowlisted_workspaces_cannot_create(): void
    {
        config(['create.enabled' => false]); $this->rejected(404, fn () => $this->brief());
        config(['create.enabled' => true, 'create.workspaces' => []]); $this->rejected(404, fn () => $this->brief());
        $this->assertSame(0, DB::table('create_conversations')->count());
    }

    public function test_viewer_can_read_but_cannot_write(): void
    {
        $c = $this->brief(); $this->owner->role = 'viewer';
        $this->assertSame($c->id, $this->conversations->conversation($this->owner, $c->id)->id);
        $this->rejected(403, fn () => $this->conversations->quote($this->owner, $c->id, 1));
    }

    public function test_message_replay_is_idempotent_but_changed_payload_is_rejected(): void
    {
        $c = $this->brief(); $input = ['content' => 'Keep my source audio.', 'expected_version' => 0, 'idempotency_key' => 'message-1'];
        $this->conversations->message($this->owner, $c->id, $input);
        $this->assertSame(1, DB::table('create_messages')->count());
        $input['content'] = 'Replace the voice'; $this->rejected(409, fn () => $this->conversations->message($this->owner, $c->id, $input));
    }

    public function test_other_workspace_conversation_is_not_found(): void
    {
        $c = $this->brief(); $other = Workspace::create(['name' => 'Other', 'status' => 'active']);
        config(['create.workspaces' => [(int) $other->id]]); $this->owner->workspace_id = $other->id;
        $this->expectException(\Illuminate\Database\RecordNotFoundException::class);
        $this->conversations->conversation($this->owner, $c->id);
    }

    public function test_cross_workspace_asset_is_rejected(): void
    {
        $c = $this->brief(); $asset = Asset::create(['workspace_id' => 999, 'asset_type' => 'video', 'status' => 'ready']);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->conversations->attach($this->owner, $c->id, $asset->id, 'source', 1);
    }

    public function test_reference_attachment_changes_version_and_invalidates_quote(): void
    {
        $c = $this->brief(); $q = $this->conversations->quote($this->owner, $c->id, 1);
        $asset = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'status' => 'ready']);
        $this->conversations->attach($this->owner, $c->id, $asset->id, 'reference', 1);
        $this->assertSame('reference', DB::table('create_attachments')->value('purpose'));
        $this->rejected(409, fn () => $this->conversations->approve($this->owner, $c->id, $q->id, 'old-quote'));
        $this->assertSame(0, DB::table('api_operations')->count());
    }

    public function test_expired_quote_and_disabled_accounting_block_admission(): void
    {
        $c = $this->brief(); $q = $this->conversations->quote($this->owner, $c->id, 1);
        $q->update(['expires_at' => now()->subMinute()]);
        $this->rejected(409, fn () => $this->conversations->approve($this->owner, $c->id, $q->id, 'expired'));
        config(['developer.operation_accounting' => false]);
        $this->rejected(503, fn () => $this->conversations->approve($this->owner, $c->id, $q->id, 'disabled'));
    }

    public function test_paid_mode_cannot_silently_call_a_provider(): void
    {
        $c = $this->brief(); config(['create.mode' => 'replicate']);
        $this->rejected(503, fn () => $this->conversations->quote($this->owner, $c->id, 1));
        Http::assertNothingSent();
    }

    public function test_admission_replay_and_capacity_use_shared_operations_without_debit(): void
    {
        [$c, $q, $run] = $this->admitted();
        $same = $this->conversations->approve($this->owner, $c->id, $q->id, 'approve-'.$c->id);
        $this->assertSame($run->id, $same->id); $this->assertSame(1, DB::table('api_operations')->count());
        $new = $this->conversations->quote($this->owner, $c->id, 1);
        $this->rejected(409, fn () => $this->conversations->approve($this->owner, $c->id, $new->id, 'second'));
        $this->assertSame(0, DB::table('credit_ledger')->count());
        $this->assertSame(100, (int) $this->workspace->fresh()->credits_monthly);
    }

    public function test_cancel_before_claim_closes_hold_and_prevents_execution(): void
    {
        [$c, , $run] = $this->admitted(); $this->runs->cancel($this->workspace->id, $c->id, $run->id);
        $this->assertNull($this->runs->claim());
        $this->assertSame('cancelled', DB::table('composition_runs')->value('status'));
        $this->assertSame(0, (int) DB::table('api_operations')->value('reserved_credits'));
    }

    public function test_running_cancellation_waits_for_worker_and_rejects_ready_callback(): void
    {
        [$c, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $this->runs->cancel($this->workspace->id, $c->id, $run->id);
        $this->assertSame('running', DB::table('api_operations')->value('status'));
        $this->assertTrue($this->runs->heartbeat($run->id, $lease['lease_token'], 1, 'Rendering')['cancel_requested']);
        $this->rejected(409, fn () => $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'preview_ready', 'summary' => 'Ready', 'bundle' => []], 'fake', 'hash'));
        $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'cancelled', 'summary' => 'Stopped'], null, null);
        $this->assertSame('cancelled', DB::table('composition_runs')->value('status'));
    }

    public function test_expired_lease_keeps_capacity_and_rejects_late_completion(): void
    {
        [, , $run] = $this->admitted(); $lease = $this->runs->claim(); $this->travel(100)->seconds();
        $this->assertNull($this->runs->claim());
        $this->assertSame('needs_attention', DB::table('api_operations')->value('status'));
        $this->rejected(409, fn () => $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'failed', 'summary' => 'Late'], null, null));
    }

    public function test_old_progress_cannot_overwrite_new_stage_and_bad_token_is_rejected(): void
    {
        [, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $this->runs->heartbeat($run->id, $lease['lease_token'], 2, 'Encoding');
        $this->runs->heartbeat($run->id, $lease['lease_token'], 1, 'Preparing');
        $this->assertSame('Encoding', DB::table('composition_runs')->value('stage'));
        $this->rejected(403, fn () => $this->runs->heartbeat($run->id, str_repeat('x', 64), 3, 'Done'));
    }

    public function test_completion_is_immutable_and_restore_creates_a_new_revision(): void
    {
        [$c, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $result = ['status' => 'preview_ready', 'summary' => 'Fixture', 'bundle' => ['index.html' => '<html></html>']];
        $this->runs->finish($run->id, $lease['lease_token'], $result, 'private/test.mp4', 'hash');
        $again = $this->runs->finish($run->id, $lease['lease_token'], $result, 'private/test.mp4', 'hash');
        $this->assertTrue($again['replayed']); $this->assertSame(1, DB::table('composition_revisions')->count());
        $revision = DB::table('composition_revisions')->first();
        $newId = $this->conversations->restore($this->owner, $c->id, $revision->id, 2);
        $this->assertNotSame($revision->id, $newId); $this->assertSame(2, DB::table('composition_revisions')->count());
        $this->assertSame([1, 2], DB::table('composition_revisions')->orderBy('number')->pluck('number')->all());
        $result['summary'] = 'Different';
        $this->rejected(409, fn () => $this->runs->finish($run->id, $lease['lease_token'], $result, 'private/test.mp4', 'hash'));
    }

    public function test_concurrent_brief_edit_preserves_finished_draft_without_replacing_head(): void
    {
        [$c, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $this->conversations->message($this->owner, $c->id, ['content' => 'Actually make it blue.', 'expected_version' => 1, 'idempotency_key' => 'changed']);
        $this->runs->finish($run->id, $lease['lease_token'], ['status' => 'preview_ready', 'summary' => 'Old draft', 'bundle' => ['index.html' => 'old']], 'private/old.mp4', 'hash');
        $this->assertNull($this->conversations->conversation($this->owner, $c->id)->head_revision_id);
        $this->assertSame(1, (int) DB::table('composition_revisions')->value('conflict'));
    }
    public function test_recovery_requires_operator_confirmation_and_fences_old_worker(): void
    {
        [, , $run] = $this->admitted(); $lease = $this->runs->claim();
        $this->travel(100)->seconds(); $this->runs->claim();
        $this->artisan('create:reconcile-fixture', ['run' => $run->id])->assertExitCode(1);
        $this->assertSame('needs_attention', DB::table('api_operations')->value('status'));
        $this->artisan('create:reconcile-fixture', ['run' => $run->id, '--worker-stopped' => true])->assertExitCode(0);
        $this->assertSame('cancelled', DB::table('api_operations')->value('status'));
        $this->rejected(403, fn () => $this->runs->heartbeat($run->id, $lease['lease_token'], 2, 'Late'));
    }

    public function test_http_contract_hides_secrets_and_requires_explicit_approval(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\AuthenticateWithJwt::class);
        $this->actingAs($this->owner);
        $c = $this->postJson('/api/v1/create/conversations', [])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/create/conversations/$c/messages", ['content' => 'Fixture', 'expected_version' => 0, 'idempotency_key' => 'http-message'])->assertCreated();
        $quote = $this->postJson("/api/v1/create/conversations/$c/quotes", ['expected_version' => 1])->assertOk()->json('data.id');
        $this->postJson("/api/v1/create/conversations/$c/runs", ['quote_id' => $quote, 'idempotency_key' => 'http-run'])->assertStatus(422);
        $this->postJson("/api/v1/create/conversations/$c/runs", ['quote_id' => $quote, 'idempotency_key' => 'http-run', 'approved' => true])->assertAccepted();
        $this->runs->claim();
        $json = $this->getJson("/api/v1/create/conversations/$c")->assertOk()->json('data');
        foreach (['lease_hash', 'input_json', 'operation_id', 'request_hash'] as $private) $this->assertArrayNotHasKey($private, $json['runs'][0]);
    }

    public function test_internal_worker_routes_reject_missing_credentials_and_null_result(): void
    {
        config(['create.worker_token' => str_repeat('a', 64)]);
        $this->postJson('/api/internal/create/claim')->assertForbidden();
        $this->withToken(str_repeat('a', 64))->postJson('/api/internal/create/claim')->assertOk()->assertJsonPath('data', null);
        $this->withToken(str_repeat('a', 64))->postJson('/api/internal/create/runs/fake/finish', ['lease_token' => str_repeat('b', 64), 'result' => 'null'])->assertStatus(422);
    }

    public function test_message_order_follows_versions_even_with_identical_timestamps(): void
    {
        $this->freezeTime(); $c = $this->brief();
        $this->conversations->message($this->owner, $c->id, ['content' => 'Second instruction', 'expected_version' => 1, 'idempotency_key' => 'second-message']);
        $quote = $this->conversations->quote($this->owner, $c->id, 2);
        $this->assertSame(['Keep my source audio.', 'Second instruction'], array_column($quote->payload_json['messages'], 'content'));
    }

    private function imageAttachment(object $c, string $purpose = 'reference'): Asset
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('minio');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1kAAAAASUVORK5CYII=');
        \Illuminate\Support\Facades\Storage::disk('minio')->put('sample.png', $png);
        $asset = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'status' => 'ready', 'storage_url' => 'minio://sample.png']);
        $this->conversations->attach($this->owner, $c->id, $asset->id, $purpose, 1);
        return $asset;
    }

    public function test_quoted_inputs_are_private_immutable_and_keep_reference_role(): void
    {
        $c = $this->brief(); $asset = $this->imageAttachment($c);
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $f = $q->payload_json['input_files'][0];
        $this->assertSame('reference', $f['purpose']);
        $this->assertSame('image/png', $f['mime_type']);
        $this->assertSame($f['sha256'], hash('sha256', \Illuminate\Support\Facades\Storage::disk('local')->get($f['storage_path'])));
        \Illuminate\Support\Facades\Storage::disk('minio')->put('sample.png', 'Changed original');
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'frozen-input');
        $claim = $this->runs->claim();
        $this->assertArrayNotHasKey('storage_path', $claim['input']['input_files'][0]);
        $served = $this->runs->inputFile($run->id, $claim['lease_token'], $asset->id);
        $this->assertSame($f['sha256'], $served['sha256']);
        $this->rejected(403, fn () => $this->runs->inputFile($run->id, str_repeat('x', 64), $asset->id));
        $this->rejected(404, fn () => $this->runs->inputFile($run->id, $claim['lease_token'], $asset->id + 1));
        $this->runs->cancel($this->workspace->id, $c->id, $run->id);
        $this->rejected(409, fn () => $this->runs->inputFile($run->id, $claim['lease_token'], $asset->id));
    }

    public function test_missing_or_changed_snapshot_and_expired_lease_cannot_be_downloaded(): void
    {
        $c = $this->brief(); $asset = $this->imageAttachment($c, 'source');
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'changed-input');
        $claim = $this->runs->claim();
        \Illuminate\Support\Facades\Storage::disk('local')->put($q->payload_json['input_files'][0]['storage_path'], 'corrupted');
        $this->rejected(409, fn () => $this->runs->inputFile($run->id, $claim['lease_token'], $asset->id));
        DB::table('composition_runs')->where('id', $run->id)->update(['lease_expires_at' => now()->subSecond()]);
        $this->rejected(409, fn () => $this->runs->inputFile($run->id, $claim['lease_token'], $asset->id));
    }

    public function test_archived_asset_cannot_be_approved_after_snapshot(): void
    {
        $c = $this->brief(); $asset = $this->imageAttachment($c);
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $asset->update(['status' => 'archived']);
        $this->rejected(409, fn () => $this->conversations->approve($this->owner, $c->id, $q->id, 'archived-input'));
        $this->assertSame(0, DB::table('api_operations')->count());
    }

    public function test_staging_rejects_remote_urls_byte_overflow_and_cleans_partial_copies(): void
    {
        $c = $this->brief(); $asset = $this->imageAttachment($c);
        $other = Asset::create(['workspace_id' => $this->workspace->id, 'asset_type' => 'image', 'status' => 'ready', 'storage_url' => 'http://127.0.0.1/private']);
        $this->conversations->attach($this->owner, $c->id, $other->id, 'source', 2);
        $this->rejected(422, fn () => $this->conversations->quote($this->owner, $c->id, 3));
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->allFiles('create/inputs'));
        $this->assertSame(0, ApiQuote::count()); Http::assertNothingSent();
        config(['create.input_file_bytes' => 10]);
        $this->rejected(422, fn () => $this->conversations->quote($this->owner, $c->id, 3));
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->allFiles('create/inputs'));
    }

    public function test_followup_quote_freezes_exact_base_revision_bundle(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim();
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'First draft', 'bundle' => ['index.html' => '<h1>First</h1>']], 'private.mp4', str_repeat('a', 64));
        $current = $this->conversations->conversation($this->owner, $c->id);
        $q = $this->conversations->quote($this->owner, $c->id, (int) $current->version);
        $this->assertSame($current->head_revision_id, $q->payload_json['base_revision_id']);
        $this->assertSame(['index.html' => '<h1>First</h1>'], $q->payload_json['base_bundle']);
        $this->assertSame(hash('sha256', json_encode(['index.html' => '<h1>First</h1>'])), $q->payload_json['base_bundle_hash']);
    }

    public function test_input_http_endpoint_requires_worker_and_current_lease(): void
    {
        $c = $this->brief(); $asset = $this->imageAttachment($c);
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'http-input');
        $claim = $this->runs->claim(); $url = "/api/internal/create/runs/{$run->id}/inputs/{$asset->id}";
        config(['create.worker_token' => str_repeat('w', 64)]);
        $this->postJson($url, ['lease_token' => $claim['lease_token']])->assertForbidden();
        $this->withToken(str_repeat('w', 64))->postJson($url, ['lease_token' => str_repeat('z', 64)])->assertForbidden();
        $this->withToken(str_repeat('w', 64))->postJson($url, ['lease_token' => $claim['lease_token']])->assertOk()->assertHeader('Content-Type', 'image/png');
        $asset->update(['status' => 'archived']);
        $this->withToken(str_repeat('w', 64))->postJson($url, ['lease_token' => $claim['lease_token']])->assertNotFound();
    }

    public function test_restored_revision_inherits_original_bytes_after_library_change(): void
    {
        $c = $this->brief(); $asset = $this->imageAttachment($c);
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $run = $this->conversations->approve($this->owner, $c->id, $q->id, 'inherit');
        $claim = $this->runs->claim();
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'preview_ready', 'summary' => 'Proof', 'bundle' => ['index.html' => '<h1>Proof</h1>']], 'preview.mp4', str_repeat('a', 64));
        $revision = DB::table('composition_revisions')->value('id');
        $restored = $this->conversations->restore($this->owner, $c->id, $revision, 3);
        $asset->update(['storage_url' => 'https://invalid.example/changed.png']);
        $next = $this->conversations->quote($this->owner, $c->id, 4);
        $this->assertSame($q->payload_json['input_files'], $next->payload_json['input_files']);
        $this->assertSame($restored, $next->payload_json['base_revision_id']);
        $this->assertSame(['index.html' => '<h1>Proof</h1>'], $next->payload_json['base_bundle']);
        $this->conversations->attach($this->owner, $c->id, $asset->id, 'source', 4);
        $this->rejected(409, fn () => $this->conversations->quote($this->owner, $c->id, 5));
    }

    public function test_input_quota_and_cleanup_keep_run_inputs_but_remove_expired_orphans(): void
    {
        $c = $this->brief(); $this->imageAttachment($c);
        config(['create.input_workspace_bytes' => 1]);
        $this->rejected(422, fn () => $this->conversations->quote($this->owner, $c->id, 2));
        config(['create.input_workspace_bytes' => 1073741824]);
        $q = $this->conversations->quote($this->owner, $c->id, 2);
        $kept = $q->payload_json['input_files'][0]['storage_path'];
        $this->conversations->approve($this->owner, $c->id, $q->id, 'retention');
        $orphanQuote = $this->conversations->quote($this->owner, $c->id, 2);
        $orphan = $orphanQuote->payload_json['input_files'][0]['storage_path'];
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        touch($disk->path($kept), now()->subDays(2)->timestamp);
        touch($disk->path($orphan), now()->subDays(2)->timestamp);
        app(\App\Services\Create\ArtifactRetentionService::class)->sweep();
        $this->assertTrue($disk->exists($kept)); $this->assertTrue($disk->exists($orphan));
        $orphanQuote->update(['expires_at' => now()->subMinute()]);
        app(\App\Services\Create\ArtifactRetentionService::class)->sweep();
        $this->assertTrue($disk->exists($kept)); $this->assertFalse($disk->exists($orphan));
    }

    public function test_attempt_replay_never_reauthorizes_execution_or_changes_request(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim(); $service = app(\App\Services\Create\AttemptService::class);
        $first = $service->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('a', 64));
        $this->assertTrue($first['may_execute']);
        $again = $service->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('a', 64));
        $this->assertFalse($again['may_execute']); $this->assertSame($first['id'], $again['id']);
        $this->rejected(409, fn () => $service->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('b', 64)));
        $this->rejected(409, fn () => $service->begin($run->id, $claim['lease_token'], 'render-2', 'render', str_repeat('a', 64)));
        $this->rejected(422, fn () => $service->begin($run->id, $claim['lease_token'], 'media-1', 'media', str_repeat('a', 64)));
        $this->assertSame(1, DB::table('api_operation_jobs')->where('status', 'pending')->count());
    }

    public function test_unsettled_attempt_prevents_run_close_and_unknown_keeps_capacity(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim(); $service = app(\App\Services\Create\AttemptService::class);
        $attempt = $service->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('a', 64));
        $this->rejected(409, fn () => $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'Stopped'], null, null));
        $service->settle($run->id, $claim['lease_token'], $attempt['id'], ['status' => 'unknown']);
        $this->assertSame('needs_attention', DB::table('api_operations')->value('status'));
        $this->assertTrue($service->settle($run->id, $claim['lease_token'], $attempt['id'], ['status' => 'unknown'])['replayed']);
        $this->runs->cancel($this->workspace->id, $c->id, $run->id);
        $this->assertSame('needs_attention', DB::table('composition_runs')->value('status'));
        $this->assertSame(1, DB::table('api_operation_jobs')->where('status', 'pending')->count());
        $this->artisan('create:reconcile-fixture', ['run' => $run->id, '--worker-stopped' => true])->assertExitCode(0);
        $this->assertSame('failed', DB::table('composition_attempts')->value('status'));
    }

    public function test_confirmed_render_receipt_settles_once_and_can_replay_after_finish(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim(); $service = app(\App\Services\Create\AttemptService::class);
        $attempt = $service->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('a', 64));
        $receipt = ['status' => 'succeeded', 'cost_microusd' => 0];
        $service->settle($run->id, $claim['lease_token'], $attempt['id'], $receipt);
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'failed', 'summary' => 'Artifact delivery failed'], null, null);
        $this->assertTrue($service->settle($run->id, $claim['lease_token'], $attempt['id'], $receipt)['replayed']);
        $this->rejected(409, fn () => $service->settle($run->id, $claim['lease_token'], $attempt['id'], ['status' => 'failed', 'cost_microusd' => 0]));
        $this->assertSame(0, DB::table('credit_ledger')->count());
    }

    public function test_synthetic_paid_policy_charges_shared_ledger_exactly_once_and_respects_allowance(): void
    {
        [, , $run] = $this->admitted(); $claim = $this->runs->claim(); $service = app(\App\Services\Create\AttemptService::class);
        // Synthetic approval only: no public quote path permits this yet, and no provider is contacted.
        $input = json_decode($run->input_json, true); $input['mode'] = 'agent';
        $input['execution_policy']['agent'] = ['provider' => 'test-provider', 'model' => 'test-model', 'credits' => 7, 'cost_limit_microusd' => 50000, 'max_calls' => 3];
        DB::table('composition_runs')->where('id', $run->id)->update(['input_json' => json_encode($input)]);
        DB::table('api_operations')->where('id', $run->operation_id)->update(['authorized_credits' => 10, 'reserved_credits' => 10]);
        $this->rejected(503, fn () => $service->begin($run->id, $claim['lease_token'], 'call-1', 'agent', str_repeat('a', 64)));
        config(['create.paid_execution_enabled' => true]);
        $attempt = $service->begin($run->id, $claim['lease_token'], 'call-1', 'agent', str_repeat('a', 64));
        $this->rejected(409, fn () => $service->begin($run->id, $claim['lease_token'], 'call-2', 'agent', str_repeat('b', 64)));
        $receipt = ['status' => 'succeeded', 'prediction_id' => 'synthetic-id', 'cost_microusd' => 12000];
        $this->rejected(422, fn () => $service->settle($run->id, $claim['lease_token'], $attempt['id'], array_merge($receipt, ['cost_microusd' => 50001])));
        $service->settle($run->id, $claim['lease_token'], $attempt['id'], $receipt);
        $this->assertTrue($service->settle($run->id, $claim['lease_token'], $attempt['id'], $receipt)['replayed']);
        $this->assertSame(93, (int) $this->workspace->fresh()->credits_monthly);
        $this->assertSame(1, DB::table('credit_ledger')->count());
        $this->assertSame($run->operation_id, DB::table('credit_ledger')->value('api_operation_id'));
        $this->assertSame($attempt['id'], json_decode(DB::table('credit_ledger')->value('metadata'), true)['composition_attempt_id']);
        $this->assertSame(7, (int) DB::table('api_operations')->value('spent_credits'));
        $this->assertSame(3, (int) DB::table('api_operations')->value('reserved_credits'));
    }

    public function test_expired_or_cancelled_attempt_cannot_start_but_cancel_can_settle_known_work(): void
    {
        [$c, , $run] = $this->admitted(); $claim = $this->runs->claim(); $service = app(\App\Services\Create\AttemptService::class);
        $attempt = $service->begin($run->id, $claim['lease_token'], 'render-1', 'render', str_repeat('a', 64));
        $this->runs->cancel($this->workspace->id, $c->id, $run->id);
        $this->rejected(409, fn () => $service->begin($run->id, $claim['lease_token'], 'render-2', 'render', str_repeat('a', 64)));
        $service->settle($run->id, $claim['lease_token'], $attempt['id'], ['status' => 'failed', 'cost_microusd' => 0]);
        $this->runs->finish($run->id, $claim['lease_token'], ['status' => 'cancelled', 'summary' => 'Stopped'], null, null);
        $this->assertSame('cancelled', DB::table('api_operations')->value('status'));
    }

}

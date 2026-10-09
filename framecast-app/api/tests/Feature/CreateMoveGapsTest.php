<?php

namespace Tests\Feature;

use App\Models\{Asset, User, Workspace};
use App\Services\Create\ConversationService;
use Illuminate\Support\Facades\{Bus, DB, Http, Redis};
use Tests\Support\BuildsDeveloperSchema;
use Tests\TestCase;

/** The gap report: reference moments no motion-kit move reproduces, grouped by kind. */
class CreateMoveGapsTest extends TestCase
{
    use BuildsDeveloperSchema;
    private User $owner;
    private Workspace $workspace;
    private ConversationService $conversations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'create_test', 'database.connections.create_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ], 'cache.default' => 'array', 'services.posthog.key' => '', 'create.enabled' => true, 'create.runtime_controls_enabled' => false, 'create.worker_ownership_required' => false, 'create.mode' => 'fixture', 'developer.operation_accounting' => true]);
        DB::purge('create_test');
        Bus::fake(); Http::preventStrayRequests(); Redis::shouldReceive('get')->andReturn(null);
        $this->buildDeveloperSchema();
        (require database_path('migrations/2026_09_25_200000_create_api_operations.php'))->up();
        (require database_path('migrations/2026_09_28_120000_create_composition_conversations.php'))->up();
        (require database_path('migrations/2026_09_29_000000_create_composition_attempts.php'))->up();
        (require database_path('migrations/2026_09_29_120000_link_composition_outputs.php'))->up();
        (require database_path('migrations/2026_09_29_130000_create_composition_reconciliations.php'))->up();
        (require database_path('migrations/2026_09_29_180000_add_create_output_metadata.php'))->up();
        (require database_path('migrations/2026_09_29_190000_create_composition_deliveries.php'))->up();
        (require database_path('migrations/2026_09_30_120000_create_create_plans.php'))->up();
        (require database_path('migrations/2026_09_30_130000_add_create_provider_consent.php'))->up();
        (require database_path('migrations/2026_10_01_120000_create_create_plan_media.php'))->up();
        (require database_path('migrations/2026_10_01_130000_create_create_styles.php'))->up();
        (require database_path('migrations/2026_10_01_140000_create_create_pronunciations.php'))->up();
        (require database_path('migrations/2026_10_01_150000_create_create_style_notes.php'))->up();
        (require database_path('migrations/2026_10_03_120000_add_create_dispatch_journal.php'))->up();
        (require database_path('migrations/2026_10_03_150000_create_composition_trace_events.php'))->up();
        (require database_path('migrations/2026_10_03_160000_widen_create_plan_media_item_index.php'))->up();
        (require database_path('migrations/2026_10_06_160000_create_vendor_incidents.php'))->up();
        (require database_path('migrations/2026_10_06_220000_create_create_planning_jobs.php'))->up();
        (require database_path('migrations/2026_10_06_230000_create_create_stored_files.php'))->up();
        (require database_path('migrations/2026_10_07_000000_create_create_runtime_controls.php'))->up();
        (require database_path('migrations/2026_10_07_010000_create_create_worker_assignments.php'))->up();
        (require database_path('migrations/2026_10_07_120000_add_notes_to_create_attachments.php'))->up();
        (require database_path('migrations/2026_10_09_160000_create_create_documents.php'))->up();
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->workspace = Workspace::create(['name' => 'Local', 'plan_tier' => 'creator', 'plan_status' => 'active', 'status' => 'active', 'credits_monthly' => 100]);
        $this->owner = User::create(['email' => 'local@example.test', 'name' => 'Local', 'role' => 'owner', 'status' => 'active']);
        $this->owner->forceFill(['workspace_id' => $this->workspace->id])->save();
        config(['create.workspaces' => [(int) $this->workspace->id], 'create.allowed_emails' => ['local@example.test', 'viewer@example.test']]);
        $this->conversations = app(ConversationService::class);
    }

    public function test_only_graphic_moments_with_no_known_move_are_listed(): void
    {
        Asset::create(['workspace_id' => $this->workspace->id, 'title' => 'ref.mp4', 'asset_type' => 'video', 'storage_url' => 'create-upload://x', 'mime_type' => 'video/mp4',
            'file_size_bytes' => 1, 'status' => 'active', 'restriction_scope' => 'workspace', 'transcription_status' => 'not_requested', 'metadata_json' => ['reference_study' => [
                'moments' => [
                    ['kind' => 'text', 'motion' => 'Letters sharpen in from blur', 'move' => 'custom', 'method' => 'motion_graphics'],
                    ['kind' => 'text', 'motion' => 'Builds word by word', 'move' => 'words', 'method' => 'motion_graphics'],
                    ['kind' => 'ui', 'motion' => 'Presenter talks to camera', 'method' => 'presenter'],
                    ['kind' => 'transition', 'motion' => 'Torn paper wipe', 'method' => 'motion_graphics'],
                ],
                'systems' => [['name' => 'Price tag', 'entry' => 'drops in and swings', 'active' => 'sways', 'move' => 'custom']],
            ]]]);
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('create:move-gaps', ['--json' => true]));
        $out = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
        $this->assertSame([1, 5, 3], [$out['studies'], $out['moments'], $out['gaps']]);
        $this->assertSame(['Letters sharpen in from blur'], $out['by_kind']['text']['examples']);
        $this->assertSame(['drops in and swings; sways'], $out['by_kind']['system']['examples']);
        $this->assertArrayNotHasKey('ui', $out['by_kind'], 'footage and presenters are not motion gaps');
    }
}

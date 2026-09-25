<?php

namespace Tests\Support;

use App\Models\Project;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The in-memory tables the developer API and OAuth tests need. Hand-built,
 * like the other feature tests here, so a test never touches the real DB.
 */
trait BuildsDeveloperSchema
{
    private function buildDeveloperSchema(): void
    {
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'plan_tier', 'plan_status', 'plan_source', 'funding_mode'] as $c) $t->string($c)->nullable();
            $t->string('status')->default('active');
            $t->unsignedBigInteger('parent_workspace_id')->nullable();
            $t->integer('credits_monthly')->default(0);
            $t->integer('credits_topup')->default(0);
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->nullable();
            foreach (['email', 'name', 'role', 'status'] as $c) $t->string($c)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });
        Schema::create('auth_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('refresh_token_hash')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('api_keys', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->string('name'); $t->string('prefix'); $t->string('token_hash');
            $t->timestamp('last_used_at')->nullable(); $t->timestamp('revoked_at')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->unsignedInteger('spend_cap_credits')->nullable(); $t->unsignedBigInteger('rotated_from_id')->nullable();
            $t->timestamps();
        });
        Schema::create('api_quotes', function (Blueprint $t) {
            $t->string('id', 32)->primary();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('api_key_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->text('payload_json');
            $t->unsignedInteger('credits_min'); $t->unsignedInteger('credits_max');
            $t->timestamp('expires_at'); $t->timestamp('consumed_at')->nullable();
            $t->string('idempotency_key', 128)->nullable();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'idempotency_key']);
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            foreach ((new Project)->getFillable() as $c) $t->text($c)->nullable();
            $t->timestamps();
        });
        Schema::create('export_jobs', function (Blueprint $t) {
            $t->id();
            foreach (['workspace_id', 'project_id', 'variant_id', 'aspect_ratio', 'language', 'file_name', 'watermark_enabled',
                'status', 'progress_percent', 'priority', 'failure_reason', 'output_asset_id', 'queued_at', 'started_at', 'completed_at'] as $c) $t->text($c)->nullable();
            $t->timestamps();
        });
        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            foreach (['workspace_id', 'asset_type', 'storage_url', 'duration_seconds', 'mime_type', 'file_name', 'title'] as $c) $t->text($c)->nullable();
            $t->timestamps();
        });
        foreach ([\App\Models\BrandKit::class, \App\Models\Channel::class, \App\Models\Niche::class, \App\Models\CaptionPreset::class, \App\Models\Character::class] as $model) {
            $m = new $model;
            Schema::create($m->getTable(), function (Blueprint $t) use ($m) {
                $t->id();
                foreach ($m->getFillable() as $c) $t->text($c)->nullable();
                if (! in_array('status', $m->getFillable(), true)) $t->text('status')->nullable();
                if (! in_array('workspace_id', $m->getFillable(), true)) $t->unsignedBigInteger('workspace_id')->nullable();
                $t->timestamps();
            });
        }
        foreach ([\App\Models\Scene::class, \App\Models\CharacterImageGeneration::class, \App\Models\ProjectHookOption::class] as $model) {
            $m = new $model;
            Schema::create($m->getTable(), function (Blueprint $t) use ($m) {
                $t->id();
                foreach ($m->getFillable() as $c) $t->text($c)->nullable();
                $t->timestamps();
            });
        }
        (require database_path('migrations/2026_09_18_120000_create_ugc_run_requests.php'))->up();
        Schema::create('voice_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->nullable();
            foreach (['provider', 'name', 'language', 'accent', 'gender_label', 'voice_type', 'provider_voice_key', 'status'] as $c) $t->string($c)->nullable();
            $t->boolean('is_cloned')->default(false);
            $t->timestamps();
        });
        Schema::create('credit_ledger', function (Blueprint $t) {
            $t->id();
            foreach (['workspace_id', 'spent_by_workspace_id', 'user_id', 'project_id', 'scene_id'] as $c) $t->unsignedBigInteger($c)->nullable();
            $t->string('operation')->nullable(); $t->integer('credits')->default(0); $t->integer('balance_after')->nullable();
            $t->decimal('upstream_cost_usd', 10, 4)->nullable(); $t->text('metadata')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_09_18_110000_add_agency_workflows.php'))->up();
        (require database_path('migrations/2026_09_25_150000_create_oauth_tables.php'))->up();
    }
}

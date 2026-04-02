<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('magic_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['email', 'expires_at']);
        });

        Schema::create('voice_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('name');
            $table->string('language');
            $table->string('accent')->nullable();
            $table->string('gender_label')->nullable();
            $table->string('voice_type')->nullable();
            $table->boolean('is_cloned')->default(false);
            $table->unsignedBigInteger('source_asset_id')->nullable();
            $table->string('provider_voice_key')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['workspace_id', 'language']);
        });

        Schema::create('caption_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('preset_type')->nullable();
            $table->string('font')->nullable();
            $table->string('font_size_rule')->nullable();
            $table->string('highlight_mode')->nullable();
            $table->string('highlight_color')->nullable();
            $table->string('animation_type')->nullable();
            $table->string('safe_area_profile')->nullable();
            $table->jsonb('line_break_rules_json')->nullable();
            $table->timestamps();
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('template_type');
            $table->text('description')->nullable();
            $table->jsonb('scene_structure_json')->nullable();
            $table->jsonb('caption_style_json')->nullable();
            $table->jsonb('voice_style_json')->nullable();
            $table->jsonb('color_font_rules_json')->nullable();
            $table->jsonb('transition_rules_json')->nullable();
            $table->jsonb('timing_rules_json')->nullable();
            $table->jsonb('supported_formats')->nullable();
            $table->jsonb('supported_languages')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('channel_id')->nullable();
            $table->string('asset_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('storage_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->decimal('duration_seconds', 8, 2)->nullable();
            $table->jsonb('dimensions_json')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('mime_type')->nullable();
            $table->jsonb('tags')->nullable();
            $table->jsonb('collection_ids')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->string('restriction_scope')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'asset_type']);
        });

        Schema::table('voice_profiles', function (Blueprint $table) {
            $table->foreign('source_asset_id')->references('id')->on('assets')->nullOnDelete();
        });

        Schema::create('brand_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('primary_color')->nullable();
            $table->string('secondary_color')->nullable();
            $table->string('accent_color')->nullable();
            $table->string('font_primary')->nullable();
            $table->string('font_secondary')->nullable();
            $table->foreignId('logo_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('default_caption_style')->nullable();
            $table->foreignId('default_voice_profile_id')->nullable()->constrained('voice_profiles')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('default_language')->nullable();
            $table->jsonb('platform_targets')->nullable();
            $table->foreignId('default_voice_profile_id')->nullable()->constrained('voice_profiles')->nullOnDelete();
            $table->foreignId('default_caption_preset_id')->nullable()->constrained('caption_presets')->nullOnDelete();
            $table->jsonb('allowed_template_ids')->nullable();
            $table->foreignId('brand_kit_id')->nullable()->constrained('brand_kits')->nullOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('channel_id')->references('id')->on('channels')->nullOnDelete();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained('channels')->nullOnDelete();
            $table->foreignId('brand_kit_id')->nullable()->constrained('brand_kits')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('templates')->nullOnDelete();
            $table->string('source_type');
            $table->longText('source_content_raw')->nullable();
            $table->longText('source_content_normalized')->nullable();
            $table->string('content_goal')->nullable();
            $table->string('platform_target')->nullable();
            $table->unsignedInteger('duration_target_seconds')->nullable();
            $table->string('aspect_ratio')->nullable();
            $table->string('tone')->nullable();
            $table->string('primary_language')->nullable();
            $table->string('title')->nullable();
            $table->longText('script_text')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->unsignedBigInteger('family_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('project_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('title')->nullable();
            $table->longText('script_text')->nullable();
            $table->string('change_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['project_id', 'revision_number']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('current_revision_id')->references('id')->on('project_revisions')->nullOnDelete();
        });

        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('scene_order');
            $table->string('scene_type');
            $table->string('label')->nullable();
            $table->longText('script_text')->nullable();
            $table->decimal('duration_seconds', 8, 2)->nullable();
            $table->foreignId('voice_profile_id')->nullable()->constrained('voice_profiles')->nullOnDelete();
            $table->jsonb('voice_settings_json')->nullable();
            $table->jsonb('caption_settings_json')->nullable();
            $table->string('visual_type')->nullable();
            $table->foreignId('visual_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->text('visual_prompt')->nullable();
            $table->string('transition_rule')->nullable();
            $table->string('status')->default('draft');
            $table->jsonb('locked_fields_json')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'scene_order']);
        });

        Schema::create('variant_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_project_id')->constrained('projects')->cascadeOnDelete();
            $table->jsonb('generation_dimensions')->nullable();
            $table->unsignedInteger('variant_count_requested')->default(0);
            $table->jsonb('lock_rules_json')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('base_project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('derived_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('variant_label');
            $table->jsonb('changed_dimensions_json')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('localization_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('source_language');
            $table->jsonb('target_languages')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('localization_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('localization_group_id')->constrained()->cascadeOnDelete();
            $table->string('target_language');
            $table->foreignId('localized_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('voice_profile_id')->nullable()->constrained('voice_profiles')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('batch_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('job_type');
            $table->string('source_entity_type');
            $table->unsignedBigInteger('source_entity_id');
            $table->unsignedInteger('requested_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status')->default('queued');
            $table->jsonb('failure_summary_json')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('variants')->nullOnDelete();
            $table->string('aspect_ratio');
            $table->string('language');
            $table->string('file_name');
            $table->boolean('watermark_enabled')->default(false);
            $table->string('status')->default('queued');
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->text('failure_reason')->nullable();
            $table->foreignId('output_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('scene_id')->nullable()->constrained('scenes')->nullOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('title');
            $table->text('body');
            $table->text('action_url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'read_at']);
        });

        Schema::create('share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->string('access_mode');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('batch_jobs');
        Schema::dropIfExists('localization_links');
        Schema::dropIfExists('localization_groups');
        Schema::dropIfExists('variants');
        Schema::dropIfExists('variant_sets');
        Schema::dropIfExists('scenes');
        Schema::dropIfExists('project_revisions');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('channels');
        Schema::dropIfExists('brand_kits');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('templates');
        Schema::dropIfExists('caption_presets');
        Schema::dropIfExists('voice_profiles');
        Schema::dropIfExists('magic_link_tokens');
        Schema::dropIfExists('auth_sessions');
    }
};

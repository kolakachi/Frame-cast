<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Scene;
use App\Services\CreditService;
use App\Services\CruiseControl\CruiseControlService;
use App\Services\CruiseControl\CruiseVideoState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Http, Schema};
use Mockery;
use Tests\TestCase;

/** The assistant sees the video's pace, state and problems, and thinks with Claude (2026-10-28). */
class CruiseAssistantContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'cruise_test', 'database.connections.cruise_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $tables = [
            'projects' => ['workspace_id', 'title', 'aspect_ratio', 'primary_language', 'tone', 'ai_broll_style', 'niche_id', 'platform_target', 'duration_target_seconds', 'default_voice_settings_json', 'assistant_brief_json', 'character_board_json', 'editor_kind', 'visual_brief'],
            'scenes' => ['project_id', 'scene_order', 'label', 'scene_type', 'script_text', 'visual_prompt', 'voice_settings_json', 'visual_style', 'character_id', 'character_ids', 'visual_type', 'duration_seconds', 'visual_asset_id', 'image_generation_settings_json', 'motion_settings_json'],
            'assets' => ['duration_seconds'], 'niches' => ['name', 'slug', 'generation_guidance'], 'export_jobs' => ['project_id', 'status', 'failure_reason'],
            'project_hook_options' => ['project_id', 'hook_text', 'hook_score', 'sort_order'], 'characters' => ['workspace_id', 'name', 'description'], 'brand_kits' => ['workspace_id', 'name'], 'workspaces' => ['name'],
        ];
        foreach ($tables as $t => $cols) Schema::create($t, function (Blueprint $b) use ($cols) { $b->id(); foreach ($cols as $c) $b->text($c)->nullable(); $b->timestamps(); });
        $credits = Mockery::mock(CreditService::class)->makePartial();
        $credits->shouldReceive('balance')->andReturn(420);
        $this->app->instance(CreditService::class, $credits);
    }

    private function video(): Project
    {
        \DB::table('niches')->insert(['id' => 1, 'name' => 'Facts & Lists', 'slug' => 'facts']);
        $p = Project::query()->forceCreate(['workspace_id' => 1, 'title' => 'Octopus facts', 'aspect_ratio' => '9:16', 'primary_language' => 'en', 'niche_id' => 1, 'platform_target' => 'tiktok', 'duration_target_seconds' => 30]);
        \DB::table('assets')->insert([['id' => 1, 'duration_seconds' => 6], ['id' => 2, 'duration_seconds' => 8]]);
        Scene::query()->forceCreate(['project_id' => $p->id, 'scene_order' => 1, 'script_text' => 'Octopuses are quite interesting creatures that live in the ocean.', 'voice_settings_json' => ['voice_id' => 'Puck', 'audio_asset_id' => 1], 'visual_prompt' => 'an octopus', 'visual_type' => 'ai_image', 'visual_asset_id' => 5]);
        Scene::query()->forceCreate(['project_id' => $p->id, 'scene_order' => 2, 'script_text' => 'They have three hearts.', 'voice_settings_json' => ['voice_id' => 'Kore', 'audio_asset_id' => 2], 'visual_prompt' => 'an octopus', 'visual_type' => 'ai_image', 'visual_asset_id' => 6]);
        Scene::query()->forceCreate(['project_id' => $p->id, 'scene_order' => 3, 'script_text' => 'And blue blood.', 'voice_settings_json' => ['voice_id' => 'Puck'], 'visual_type' => 'ai_image', 'image_generation_settings_json' => ['last_error' => 'safety filter']]);
        return $p;
    }

    public function test_it_finds_the_problems_worth_fixing(): void
    {
        $p = $this->video();
        $state = app(CruiseVideoState::class)->for($p, Scene::query()->where('project_id', $p->id)->orderBy('scene_order')->get());
        $this->assertStringContainsString('NICHE PLAYBOOK', $state['project_lines']);
        $this->assertStringContainsString('target_length: 30 s', $state['project_lines']);
        foreach (['hook: the first scene takes 6 s', 'scene 3: has no picture', 'scene 3: no voice recorded', 'scene 2: one still held 8 s', 'scenes 1, 2: the same picture prompt repeated', 'scene 2: voice "Kore" differs'] as $issue) {
            $this->assertStringContainsString($issue, $state['issues_lines']);
        }
        $this->assertStringContainsString('scene 3: last picture failed (safety filter)', $state['state_lines']);
        $this->assertStringContainsString('credits balance: 420', $state['state_lines']);
    }

    public function test_a_turn_runs_on_claude_with_the_state_in_its_prompt(): void
    {
        config(['services.anthropic.key' => 'test', 'services.cruise.provider' => 'anthropic', 'services.cruise.model' => 'claude-sonnet-5-5']);
        $p = $this->video();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Here: {"reply_to_user": "Your hook is slow; tighten scene 1.", "actions": []}']], 'usage' => ['input_tokens' => 9000, 'output_tokens' => 120]])]);
        $out = app(CruiseControlService::class)->resolve('review my video', $p, null);
        $this->assertSame('Your hook is slow; tighten scene 1.', $out['reply_to_user']);
        Http::assertSent(function ($r) {
            $system = $r->data()['system'][0]['text'] ?? '';
            return $r->data()['model'] === 'claude-sonnet-5-5' && str_contains($system, 'THINGS TO FIX') && str_contains($system, 'hook: the first scene takes 6 s')
                && ($r->data()['system'][0]['cache_control']['type'] ?? '') === 'ephemeral';
        });
    }
}

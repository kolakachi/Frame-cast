<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\DashboardController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

/** #11 (2026-10-12): the sample video every account gets is not their first video. */
class FirstVideoStepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'first_video_test', 'database.connections.first_video_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'onboarding.sample_project_id' => 48]);
        DB::purge('first_video_test');
        Schema::create('projects', function (Blueprint $t) { $t->id(); foreach (['workspace_id', 'title', 'source_content_raw', 'visual_brief'] as $c) $t->text($c)->nullable(); $t->timestamps(); });
        Schema::create('create_conversations', function (Blueprint $t) { $t->uuid('id'); $t->text('workspace_id')->nullable(); });
        Schema::create('composition_revisions', function (Blueprint $t) { $t->id(); $t->text('conversation_id')->nullable(); });
        DB::table('projects')->insert(['id' => 48, 'workspace_id' => 1, 'title' => 'Wyvstudio Ad', 'source_content_raw' => 'A founder explaining how wyvstudio helped']);
    }

    public function test_the_sample_is_not_their_first_video(): void
    {
        DB::table('projects')->insert(['workspace_id' => 7, 'title' => 'Wyvstudio Ad', 'source_content_raw' => 'A founder explaining how wyvstudio helped', 'visual_brief' => json_encode(['onboarding_sample' => true])]);
        DB::table('projects')->insert(['workspace_id' => 8, 'title' => 'Wyvstudio Ad', 'source_content_raw' => 'A founder explaining how wyvstudio helped']); // an older, unmarked copy
        $this->assertFalse(DashboardController::steps(7)['first_video']);
        $this->assertFalse(DashboardController::steps(8)['first_video']);
        DB::table('projects')->insert(['workspace_id' => 8, 'title' => 'My bakery ad', 'source_content_raw' => 'Sourdough for people nearby', 'visual_brief' => json_encode(['ugc_format' => 'one_shot'])]);
        $this->assertTrue(DashboardController::steps(8)['first_video']);
    }
}

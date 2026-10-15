<?php

namespace Tests\Feature;

use App\Services\Ugc\UgcLearn;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Cache, DB, Http, Schema};
use Tests\TestCase;

class UgcLearnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'learn_test', 'database.connections.learn_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'services.anthropic.key' => 'test-key', 'services.ugc.lessons_apply' => true]);
        DB::purge('learn_test');
        (include base_path('database/migrations/2026_10_15_120000_create_ugc_learning_tables.php'))->up();
        Cache::forget('ugc-lessons');
    }

    private function answer(array $json): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($json)]]])]);
    }

    public function test_a_take_is_reviewed_and_only_real_pitfalls_are_kept(): void
    {
        $this->answer(['score' => 6, 'pitfalls' => [
            ['area' => 'action', 'what' => 'The cap never comes off.', 'role' => 'sheet', 'lesson' => 'Give the cap its own block with time to twist it off.'],
            ['area' => 'nonsense', 'what' => 'x', 'role' => 'nobody', 'lesson' => 'Name the product once.'],
            ['area' => 'room', 'what' => 'y', 'role' => 'planner', 'lesson' => ''],
        ]]);
        $review = app(UgcLearn::class)->review(['project_id' => 7, 'workspace_id' => 3, 'ugc_type' => 'unboxing', 'engine' => 'omni', 'outcome' => 'delivered', 'brief' => 'Open the jar.', 'clips' => [], 'checks' => []]);
        $this->assertSame(6, $review['score']);
        $this->assertCount(2, $review['pitfalls'], 'a pitfall without a lesson is dropped');
        $this->assertSame(['other', 'director'], [$review['pitfalls'][1]['area'], $review['pitfalls'][1]['role']], 'unknown area and role fall back');
        $this->assertSame(1, DB::table('ugc_take_reviews')->where('project_id', 7)->where('outcome', 'delivered')->count());
    }

    public function test_reviews_merge_into_at_most_ten_lessons_that_each_role_reads(): void
    {
        DB::table('ugc_take_reviews')->insert(['project_id' => 7, 'outcome' => 'delivered', 'pitfalls' => json_encode([['area' => 'action', 'what' => 'x', 'role' => 'sheet', 'lesson' => 'l']]), 'created_at' => now(), 'updated_at' => now()]);
        $lessons = array_map(fn ($i) => ['role' => $i % 2 ? 'sheet' : 'director', 'lesson' => "Lesson $i.", 'seen' => 3], range(1, 12));
        $lessons[] = ['role' => 'janitor', 'lesson' => 'Ignored.', 'seen' => 9];
        $this->answer(['lessons' => $lessons]);
        $kept = app(UgcLearn::class)->consolidate();
        $this->assertCount(10, $kept);
        $this->assertStringContainsString('- Lesson 1.', UgcLearn::for('sheet'));
        $this->assertStringNotContainsString('Lesson 2.', UgcLearn::for('sheet'));
        $this->assertStringContainsString('- Lesson 2.', UgcLearn::for('director'));
        $this->assertSame('', UgcLearn::for('planner'));
        config(['services.ugc.lessons_apply' => false]);
        $this->assertSame('', UgcLearn::for('director'), 'switched off');
    }

    public function test_no_new_reviews_leaves_the_lessons_alone(): void
    {
        Http::fake();
        $this->artisan('ugc:learn-lessons')->expectsOutput('No new reviews since the last lessons list.')->assertSuccessful();
        Http::assertNothingSent();
    }
}

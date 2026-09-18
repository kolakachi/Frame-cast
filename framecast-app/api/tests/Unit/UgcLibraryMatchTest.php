<?php

namespace Tests\Unit;

use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Ugc\UgcShotPlanner;
use Tests\TestCase;

/**
 * The other half of reading a reference: the shape comes from the ad they
 * admire, the substance from footage they already own. Their real product
 * beats anything we would generate of it, and nothing is generated at all.
 */
class UgcLibraryMatchTest extends TestCase
{
    private function planner(array $segments): UgcShotPlanner
    {
        $reply = json_encode(['format' => 'demo', 'reasoning' => 'r', 'segments' => $segments]);
        $ai = new class($reply) implements AIGenerationAdapter
        {
            public array $vars = [];

            public function __construct(private string $reply) {}

            public function generate(string $k, array $v, int $m = 900, float $t = 0.4, array $o = []): array
            {
                $this->vars = $v;

                return ['content' => $this->reply];
            }
        };

        return new UgcShotPlanner($ai);
    }

    private function shot(array $over = []): array
    {
        return array_merge([
            'kind' => 'b_roll', 'source' => 'upload', 'script_text' => 'Here it is running.',
            'seconds' => 5, 'visual_brief' => 'Screen recording of the app.',
        ], $over);
    }

    private function library(): array
    {
        return [
            ['id' => 11, 'kind' => 'video', 'title' => 'App screen recording', 'seconds' => 8.0],
            ['id' => 12, 'kind' => 'image', 'title' => 'Product on a desk'],
        ];
    }

    public function test_a_clip_they_own_is_assigned_to_the_shot(): void
    {
        $planner = $this->planner([$this->shot(['asset_id' => 11])]);

        $out = $planner->plan('', 'an app', 'ctx', 30, 'en', [], 'demo', [], $this->library());

        $this->assertSame(11, $out['segments'][0]['asset_id']);
        $this->assertSame('upload', $out['segments'][0]['source']);
    }

    public function test_an_id_we_never_offered_is_dropped(): void
    {
        // 99 belongs to someone else, or to nobody.
        $planner = $this->planner([$this->shot(['asset_id' => 99])]);

        $out = $planner->plan('', 'an app', 'ctx', 30, 'en', [], 'demo', [], $this->library());

        $this->assertNull($out['segments'][0]['asset_id'], 'a hallucinated id must never reach the user');
    }

    public function test_the_shot_still_says_it_needs_footage_after_a_drop(): void
    {
        $planner = $this->planner([$this->shot(['asset_id' => 99])]);

        $out = $planner->plan('', 'an app', 'ctx', 30, 'en', [], 'demo', [], $this->library());

        $this->assertStringContainsString('needs selected upload footage', implode(' ', $out['warnings']));
    }

    public function test_the_director_is_shown_their_clips_with_ids(): void
    {
        $planner = $this->planner([$this->shot(['asset_id' => 11])]);
        $planner->plan('', 'an app', 'ctx', 30, 'en', [], 'demo', [], $this->library());

        $brief = (new \ReflectionProperty($planner, 'ai'))->getValue($planner)->vars['library'] ?? '';
        $this->assertStringContainsString('id 11', $brief);
        $this->assertStringContainsString('App screen recording', $brief);
    }

    public function test_with_nothing_uploaded_the_director_is_told_so(): void
    {
        $planner = $this->planner([$this->shot(['source' => 'generate', 'asset_id' => null])]);
        $planner->plan('', 'an app', 'ctx', 30, 'en', [], 'demo', [], []);

        $brief = (new \ReflectionProperty($planner, 'ai'))->getValue($planner)->vars['library'] ?? '';
        $this->assertStringContainsString('none uploaded', $brief);
    }
}

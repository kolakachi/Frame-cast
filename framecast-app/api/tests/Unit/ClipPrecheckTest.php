<?php

namespace Tests\Unit;

use App\Services\Generation\ClipPrecheck;
use App\Services\Generation\VideoCraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A look before every paid clip (owner, 2026-11-05): prompt problems are fixed, frame problems reported, all kept. */
class ClipPrecheckTest extends TestCase
{
    private const PROMPT = 'She opens the jar, smells it, then laughs as her friends walk past behind her. Sound: the character says exactly: "This changed my mornings" — nothing else is spoken. [Image1] is the cast.';

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_11_05_000000_create_clip_prechecks.php'))->up();
        config(['services.anthropic.key' => 'test-key', 'create.mode' => 'live', 'create.clip_precheck' => true]);
    }

    private static function answer(array $json): array
    {
        return ['content' => [['type' => 'text', 'text' => json_encode($json)]], 'usage' => ['input_tokens' => 900, 'output_tokens' => 120]];
    }

    public function test_the_answer_is_read_as_issues_and_a_rewrite(): void
    {
        $a = ClipPrecheck::parse('Here: {"prompt_issues": ["Three actions in one shot."], "frame_issues": [], "rewrite": "She opens the jar."}');
        $this->assertSame(['Three actions in one shot.'], $a['prompt_issues']);
        $this->assertSame([], $a['frame_issues']);
        $this->assertSame('She opens the jar.', $a['rewrite']);
        $this->assertNull(ClipPrecheck::parse('no json here'));
        $this->assertNull(ClipPrecheck::parse('{"other": 1}'));
    }

    public function test_a_rewrite_must_keep_quoted_lines_image_names_and_timings(): void
    {
        $kept = str_replace('opens the jar, smells it, then laughs as her friends walk past behind her', 'opens the jar and breathes in, alone in a quiet kitchen', self::PROMPT);
        $this->assertSame($kept, ClipPrecheck::keepsFixedParts(self::PROMPT, $kept));
        $this->assertNull(ClipPrecheck::keepsFixedParts(self::PROMPT, str_replace('This changed my mornings', 'This changed my life', $kept)), 'a quoted line changed');
        $this->assertNull(ClipPrecheck::keepsFixedParts(self::PROMPT, str_replace('[Image1]', 'The first image', $kept)), 'an image name dropped');
        $this->assertNull(ClipPrecheck::keepsFixedParts(self::PROMPT, 'She opens the jar.'), 'far shorter');
        $this->assertNull(ClipPrecheck::keepsFixedParts('00:00-00:02 she lifts it. 00:02-00:04 she smiles.', '00:00-00:03 she lifts it and smiles, in one easy move.'), 'a timing changed');
    }

    public function test_a_prompt_problem_is_rewritten_checked_again_and_kept(): void
    {
        $fixed = str_replace('opens the jar, smells it, then laughs as her friends walk past behind her', 'opens the jar and breathes in, alone in a quiet kitchen', self::PROMPT);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(self::answer(['prompt_issues' => ['Three actions in one shot.', 'People cross behind her.'], 'frame_issues' => [], 'rewrite' => $fixed]))
            ->push(self::answer(['prompt_issues' => [], 'frame_issues' => [], 'rewrite' => null]))]);

        $r = app(ClipPrecheck::class)->check(self::PROMPT, [], 'create', ['workspace_id' => 1, 'ref' => 'plan:1', 'seconds' => 5]);

        $this->assertSame($fixed, $r['prompt']);
        $this->assertTrue($r['rewritten']);
        $this->assertTrue($r['ok'], 'fixed, nothing left to stop for');
        Http::assertSentCount(2);
        $row = DB::table('clip_prechecks')->find($r['id']);
        $this->assertSame('rewritten', $row->verdict);
        $this->assertSame('create', $row->source);
        $this->assertSame($fixed, $row->prompt_after);
        $this->assertSame(['Three actions in one shot.', 'People cross behind her.'], json_decode($row->issues_json, true)['prompt']);
    }

    public function test_a_rewrite_that_drops_the_spoken_line_is_not_used(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(self::answer(['prompt_issues' => ['Three actions.'], 'frame_issues' => [], 'rewrite' => 'She opens the jar and breathes in, alone in a quiet kitchen, smiling to herself as the morning light comes in. [Image1] is the cast.']))]);

        $r = app(ClipPrecheck::class)->check(self::PROMPT, [], 'create');

        $this->assertSame(self::PROMPT, $r['prompt']);
        $this->assertFalse($r['rewritten']);
        Http::assertSentCount(1);
        $this->assertSame('prompt', DB::table('clip_prechecks')->find($r['id'])->verdict);
    }

    public function test_frame_problems_are_reported_and_the_prompt_is_left_alone(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(self::answer(['prompt_issues' => [], 'frame_issues' => ['The end frame shows a different woman.'], 'rewrite' => null]))]);

        $r = app(ClipPrecheck::class)->check('She stands up from the desk.', [], 'editor');

        $this->assertSame('She stands up from the desk.', $r['prompt']);
        $this->assertSame(['The end frame shows a different woman.'], $r['frame_issues']);
        $this->assertSame('frames', DB::table('clip_prechecks')->find($r['id'])->verdict);
    }

    public function test_a_failed_or_switched_off_check_lets_the_clip_through_unchanged(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response('overloaded', 529)]);
        $r = app(ClipPrecheck::class)->check(self::PROMPT, [], 'ugc');
        $this->assertTrue($r['ok']);
        $this->assertSame(self::PROMPT, $r['prompt']);
        $this->assertSame(0, DB::table('clip_prechecks')->count());

        config(['create.clip_precheck' => false]);
        Http::fake();
        app(ClipPrecheck::class)->check(self::PROMPT, [], 'ugc');
        Http::assertNothingSent();
    }

    public function test_the_avoid_list_fits_each_reader(): void
    {
        $plan = VideoCraft::rules('plan');
        $this->assertStringContainsString('crossing in front of each other', $plan);
        $this->assertStringContainsString('mirrors', $plan);
        $this->assertStringContainsString('one after another', $plan);
        // UGC shoots mirror selfies, top-down hands, timed multi-step sheets and products in hand on purpose.
        $ugc = VideoCraft::rules('ugc');
        $this->assertStringNotContainsString('mirrors', $ugc);
        $this->assertStringNotContainsString('more than one action', $ugc);
        $this->assertStringNotContainsString('small handheld items', $ugc);
        $this->assertStringContainsString('crowds', $ugc);
        $this->assertStringNotContainsString('camera move has a reason', VideoCraft::rules('storyboard'));
        $this->assertStringContainsString('Video models garble', \App\Services\Create\Planning\PlanPrompt::system());
    }
}

<?php

namespace Tests\Unit;

use App\Services\Ugc\UgcPlan;
use Tests\TestCase;

/**
 * Anchoring exists so a rewrite moves the visual with the meaning. Before it,
 * `seconds` re-derived from the new word count while `visual_brief` kept
 * pointing at the sentence that used to be there — the shot came out correctly
 * timed and answering words nobody says any more.
 */
class UgcAnchorTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function shots(array ...$overrides): array
    {
        return array_map(fn (array $o) => array_merge([
            'kind' => 'on_camera',
            'script_text' => 'I used to spend hours on this.',
            'seconds' => 5,
            'visual_brief' => 'Medium close-up, kitchen, morning light.',
            'motion_prompt' => '',
            'voice_direction' => '',
            'headline' => '',
            'source' => null,
        ], $o), $overrides);
    }

    public function test_a_shot_anchors_to_its_own_words_by_default(): void
    {
        $out = UgcPlan::normalise($this->shots(['script_text' => 'This saved me a whole afternoon.']), 'story');

        $this->assertSame('This saved me a whole afternoon.', $out[0]['anchor']);
        $this->assertSame('establish', $out[0]['anchor_role']);
    }

    public function test_a_cutaway_can_answer_a_claim_made_in_another_shot(): void
    {
        $out = UgcPlan::normalise($this->shots(
            ['script_text' => 'It cut my editing time in half.'],
            ['kind' => 'b_roll', 'source' => 'upload', 'script_text' => 'Here it is running.',
             'anchor' => 'It cut my editing time in half.', 'anchor_role' => 'prove'],
        ), 'demo');

        $this->assertSame('It cut my editing time in half.', $out[1]['anchor']);
        $this->assertSame('prove', $out[1]['anchor_role']);
    }

    public function test_an_unknown_role_falls_back_by_kind_rather_than_failing(): void
    {
        $out = UgcPlan::normalise($this->shots(
            ['anchor_role' => 'vibes'],
            ['kind' => 'b_roll', 'source' => 'stock', 'script_text' => 'And it looks like this.', 'anchor_role' => ''],
        ), 'demo');

        $this->assertSame('establish', $out[0]['anchor_role']);
        $this->assertSame('illustrate', $out[1]['anchor_role']);
    }

    public function test_a_shot_whose_line_survives_the_rewrite_is_not_stale(): void
    {
        $out = UgcPlan::normalise($this->shots(['script_text' => 'It cut my editing time in half.']), 'story');
        $out = UgcPlan::reanchor($out, 'Honestly? It cut my editing time in half. That is the whole pitch.');

        $this->assertFalse($out[0]['stale']);
        $this->assertSame(0, UgcPlan::staleCount($out));
    }

    public function test_punctuation_and_casing_are_not_a_meaning_change(): void
    {
        $out = UgcPlan::normalise($this->shots(['script_text' => 'It cut my editing time in half.']), 'story');
        $out = UgcPlan::reanchor($out, 'it cut my editing time in half!!');

        $this->assertFalse($out[0]['stale']);
    }

    public function test_a_shot_left_pointing_at_a_deleted_line_is_stale(): void
    {
        $out = UgcPlan::normalise($this->shots(
            ['script_text' => 'I used to spend hours on this.'],
            ['kind' => 'b_roll', 'source' => 'upload', 'script_text' => 'Here it is running.',
             'anchor' => 'It cut my editing time in half.', 'anchor_role' => 'prove'],
        ), 'demo');

        // The opening survives the rewrite; the claim the cutaway was proving
        // is replaced. Only the cutaway is now answering nothing.
        $out = UgcPlan::reanchor($out, 'I used to spend hours on this. Here it is running.');

        $this->assertFalse($out[0]['stale'], 'the opening line is still in the script');
        $this->assertTrue($out[1]['stale'], 'the cutaway no longer proves anything that is said');
        $this->assertSame(1, UgcPlan::staleCount($out));
    }

    public function test_deleting_a_spoken_line_makes_that_shot_stale_too(): void
    {
        $out = UgcPlan::normalise($this->shots(['script_text' => 'It cut my editing time in half.']), 'story');
        $out = UgcPlan::reanchor($out, 'Completely different words now.');

        $this->assertTrue($out[0]['stale']);
    }

    public function test_a_silent_reaction_is_never_stale_on_the_scripts_account(): void
    {
        $out = UgcPlan::normalise([[
            'kind' => 'reaction', 'script_text' => '', 'seconds' => 5,
            'visual_brief' => 'Close-up, surprised then amused.',
            'motion_prompt' => 'Raise eyebrows, then smile.',
            'headline' => 'When it finally renders',
        ]], 'reaction');
        $out = UgcPlan::reanchor($out, 'Completely different words.');

        $this->assertFalse($out[0]['stale']);
        $this->assertSame('react', $out[0]['anchor_role']);
    }

    public function test_stale_shots_are_surfaced_as_a_warning(): void
    {
        $out = UgcPlan::normalise($this->shots(
            ['script_text' => 'I used to spend hours on this.'],
            ['kind' => 'b_roll', 'source' => 'upload', 'script_text' => 'Here it is running.',
             'anchor' => 'It cut my editing time in half.', 'anchor_role' => 'prove'],
        ), 'demo');
        $out = UgcPlan::reanchor($out, 'I used to spend hours on this. Here it is running.');

        $warnings = implode(' ', UgcPlan::warnings($out));
        $this->assertStringContainsString('no longer contains', $warnings);
    }
}

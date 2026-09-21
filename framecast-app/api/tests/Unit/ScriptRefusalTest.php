<?php

namespace Tests\Unit;

use App\Support\ScriptText;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScriptRefusalTest extends TestCase
{
    /** The exact text that shipped as a 60-second video and earned a 1/5. */
    public function test_detects_the_refusal_that_reached_a_customer(): void
    {
        $this->assertTrue(ScriptText::looksLikeRefusal(
            "I can't write this one — it reads as personalized romantic/intimate roleplay "
            ."content rather than a general social video script, and it includes a "
            ."foot-focused fetish element I'm not comfortable producing.\n\nIf you're working "
            ."on a TikTok script for the \"general\" niche with a warm, educational tone, I'd "
            ."genuinely love to help — just point me toward a real topic."
        ));
    }

    #[DataProvider('refusals')]
    public function test_detects_a_decline(string $script): void
    {
        $this->assertTrue(ScriptText::looksLikeRefusal($script), $script);
    }

    public static function refusals(): array
    {
        return [
            ["I can't create that video for you."],
            ["I cannot write a script about this topic."],
            ["I'm sorry, but I can't help with this request."],
            ["Sorry — I won't be able to produce this."],
            ["I am unable to generate content of this kind."],
            ["I'm not comfortable producing this script."],
            ["As an AI language model, I must decline."],
            ["I can’t write this — the smart quote still counts."],
        ];
    }

    #[DataProvider('scripts')]
    public function test_leaves_a_real_script_alone(string $script): void
    {
        $this->assertFalse(ScriptText::looksLikeRefusal($script), $script);
    }

    public static function scripts(): array
    {
        return [
            // The phrase that makes a bare "I can't help" match unsafe.
            ["I can't help but notice how many people get this wrong."],
            ["I can't believe this actually works, but watch."],
            ["I can't stop thinking about what happened next."],
            ["Here's your chance to fix that today."],
            ["I won't lie to you: this takes practice."],
            ["Sorry, not sorry — this is the hill I die on."],
            ["Can't write? Start with one sentence a day."],
            [''],
        ];
    }

    public function test_a_refusal_is_not_mangled_into_a_script_by_the_preamble_stripper(): void
    {
        // stripPreamble must stay narrow enough that it never disguises a
        // refusal as a usable script by trimming its opening.
        $refusal = "Sorry, I can't write this one.";
        $this->assertTrue(ScriptText::looksLikeRefusal(ScriptText::stripPreamble($refusal)));
    }
}

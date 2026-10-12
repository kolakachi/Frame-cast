<?php

namespace Tests\Unit;

use App\Support\ScriptText;
use PHPUnit\Framework\TestCase;

class ScriptTextLooksLikeScriptTest extends TestCase
{
    private const SESSION_TWO = "Welcome back to the Coaching Program! Now that you've completed Session One and started building your foundation, it's time to move into Session Two. And this is where things start getting interesting, because now we're going to talk about marketing, outreach, and building relationships with the people who can help you fill your beds. One thing I always tell people is that you don't have to wait until you have a house to start building relationships. Matter of fact, I encourage you to start building those relationships before you even secure your first property. In this session, I'm going to show you where to find referral partners, what to say when you reach out, and how to keep track of your referrals. We'll also talk about the screening process, because just because somebody needs housing doesn't automatically mean they're a good fit for your program. So grab your notebook, pay attention, and let's get into it!";

    public function test_a_pasted_script_is_told_from_an_idea(): void
    {
        $this->assertTrue(ScriptText::looksLikeScript(self::SESSION_TWO), 'workspace 45\'s session intro, pasted into Write a Prompt');
        $this->assertEqualsWithDelta(70, ScriptText::spokenSeconds(self::SESSION_TWO), 10);
        $this->assertFalse(ScriptText::looksLikeScript('The mysterious disappearance of the Beaumont family in 1966.'), 'an idea');
        $this->assertFalse(ScriptText::looksLikeScript('Make a video about '.self::SESSION_TWO), 'a request for a video, however long');
        $this->assertFalse(ScriptText::looksLikeScript(str_repeat('Coffee subscriptions grow quickly. Roasters ship weekly. Prices start at eighteen dollars. ', 8)), 'notes with no one spoken to');
    }
}

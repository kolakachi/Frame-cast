<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Niche extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon_emoji',
        'default_template_type',
        'default_visual_style',
        'default_caption_preset_name',
        'default_voice_tone',
        'default_music_mood',
        'generation_guidance',
    ];

    /**
     * Per-niche "playbook" — the DNA of a good video in this niche (structure,
     * hook style, pacing, CTA, audience). Threaded into the script/breakdown/hook
     * prompts so a Finance video and a Horror video come out structurally right,
     * not just tonally tinted. Source of truth + fallback when the (optional,
     * editable) generation_guidance column is empty. `_default` covers custom /
     * no-niche projects.
     */
    public const PLAYBOOK = [
        'product' => 'Hook on the buyer\'s question or a blunt verdict, walk through 2-3 concrete features with honest pros and cons, give a balanced recommendation, end with a clear "is it worth it?" CTA. Specific and credible — no hype, no invented specs or pricing.',
        'product-launch' => 'Open with the big "what\'s new" hook, demo 2-3 standout features and the outcome each unlocks (benefit before feature), end on a strong sign-up / try-it CTA. Confident and clean.',
        'ad-creative' => 'Scroll-stopping hook in the first 2 seconds, name the problem, present the product as the fix with one proof point, close with a direct CTA. Punchy, high-contrast, sound-off captions, built for paid traffic. Never invent testimonials, pricing, or guarantees.',
        'explainer' => 'Hook with the question or payoff ("how to X in 60 seconds"), teach in clear numbered steps or one strong analogy, resolve with the key takeaway and a soft CTA. Simple and precise — no jargon dumps.',
        'brand-story' => 'Open on a relatable moment or turning point, tell the origin / mission / transformation arc, land on what it means for the viewer. Warm, authentic, first-person — build trust before selling.',
        'motivation' => 'Faceless motivation short. Line 1 is a hard truth or a reframe, under 12 words. Build momentum in short, rhythmic, quotable lines with one concrete image or example, not abstractions. End on one line the viewer would save or repeat, never a sales pitch. Deep, steady, intense.',
        // Faceless formats (2026-10-28): the composer's main audience again. Each sets the hook, the beats, the pacing and
        // an ending that keeps the viewer (a loop or a follow line), never a sales CTA.
        'facts' => 'Faceless facts short. Line 1 is the hook: the single most surprising fact, stated as a claim or a question, under 12 words. Then 3-5 facts, one per beat: the fact, then one line on why it is wild; save the best for last. No intro, no greeting, no "did you know" filler. Close with a line that loops back to the opening or "Follow for part 2", never a sales pitch. Only real, widely documented facts: never invent numbers, dates or names, and leave out anything you are not sure of.',
        'history' => 'Faceless history short. Open cold on the most dramatic moment (a person, a decision, a disaster), never a "In 1912..." preamble. Then the context in one or two beats, rising stakes, the turn or the reveal, and close on what it changed, with a last line that makes the viewer want the next story. Measured documentary narration. Only well-documented names and dates; when a detail is disputed say so, or leave it out.',
        'science' => 'Faceless science short. Open with a mind-bending question or claim. Explain with one vivid analogy, step from simple to surprising in 2-3 beats, and land the "so what" for everyday life. Clear and curious, never condescending. State established science as fact and theories as theories; never invent figures.',
        'story' => 'Faceless storytelling short (a narrated story, Reddit-style or a fable). The first line drops the viewer into the middle of the situation. Setup in a beat or two, rising tension, a twist, a resolution, and a last line that invites comments. Close first person or present tense, short spoken sentences with natural pauses. It is a story: never claim invented events are real, and never name real private people.',
        'scary' => 'Faceless scary or mystery short. Open with one unsettling line. Build slowly with specific, sensory details and small clues, escalate, deliver the twist or reveal near the end, and close on a lingering question. Suspense over gore. Frame legends and unsolved cases as legends and unsolved; never present rumours as fact.',
        'ai-tips' => 'Faceless AI and tech tips short. Hook with the outcome ("This free AI tool writes your emails for you."). Then 2-4 tools or steps, each with what it does and one concrete use. Finish with "Save this for later" or a follow line. Fast, practical, friendly. Name only real, well-known tools or the ones the brief names; never invent features, prices or results.',
        '_default' => 'Open with a strong scroll-stopping hook, deliver the core value in tight caption-friendly lines, and end with a clear takeaway or CTA. Follow the stated tone and goal.',
    ];

    /** Faceless formats, listed first in the wizard (the composer's main audience, 2026-10-28). */
    public const FACELESS = ['facts', 'history', 'science', 'story', 'scary', 'ai-tips', 'motivation'];

    /**
     * What each niche sets up by default when the user has not chosen: the narrator (a Gemini voice and its delivery),
     * the caption look (a caption animation preset, see web/src/composables/captionPresets.js) and the seconds per
     * scene. Before this the niche's caption preset named presets that do not exist, the voice was the first in an
     * alphabetical list, and every niche cut a scene every 5 seconds.
     */
    public const DEFAULTS = [
        'facts' => ['voice' => 'Puck', 'voice_prompt' => 'Quick, punchy and amazed, like sharing a fact you can barely believe.', 'captions' => ['animation' => 'beast', 'font' => 'Montserrat', 'highlight_mode' => 'word_by_word'], 'seconds' => 3.0],
        'history' => ['voice' => 'Charon', 'voice_prompt' => 'Warm documentary narrator, measured pace, a little gravity on the turns.', 'captions' => ['animation' => 'karaoke', 'font' => 'Montserrat'], 'seconds' => 4.0],
        'science' => ['voice' => 'Iapetus', 'voice_prompt' => 'Clear and curious, like explaining something amazing to a friend.', 'captions' => ['animation' => 'karaoke', 'font' => 'Montserrat'], 'seconds' => 4.0],
        'story' => ['voice' => 'Sulafat', 'voice_prompt' => 'Calm, intimate storyteller who pauses before every turn.', 'captions' => ['animation' => 'karaoke', 'font' => 'Montserrat'], 'seconds' => 5.0],
        'scary' => ['voice' => 'Algenib', 'voice_prompt' => 'Slow, low and hushed, building dread line by line.', 'captions' => ['animation' => 'glitch', 'font' => 'Bebas Neue'], 'seconds' => 4.0],
        'ai-tips' => ['voice' => 'Achird', 'voice_prompt' => 'Friendly, fast and practical, like a tech-savvy friend.', 'captions' => ['animation' => 'box', 'font' => 'Nunito'], 'seconds' => 3.0],
        'motivation' => ['voice' => 'Alnilam', 'voice_prompt' => 'Deep, steady and intense, with weight on the last line.', 'captions' => ['animation' => 'punch', 'font' => 'Montserrat'], 'seconds' => 4.0],
        'product' => ['captions' => ['animation' => 'karaoke', 'font' => 'Montserrat']],
        'product-launch' => ['captions' => ['animation' => 'punch', 'font' => 'Montserrat']],
        'ad-creative' => ['captions' => ['animation' => 'beast', 'font' => 'Montserrat']],
        'explainer' => ['captions' => ['animation' => 'box', 'font' => 'Nunito']],
        'brand-story' => ['captions' => ['animation' => 'karaoke', 'font' => 'Montserrat']],
    ];

    /** This niche's defaults (voice, voice_prompt, captions, seconds), or none. */
    public static function defaultsFor(?string $slug): array
    {
        return self::DEFAULTS[$slug ?? ''] ?? [];
    }

    /** Seconds per still scene for this niche, or null for the visual mode's own pace. */
    public static function secondsFor(?string $slug): ?float
    {
        $s = self::DEFAULTS[$slug ?? '']['seconds'] ?? null;
        return $s ? (float) $s : null;
    }

    /** Playbook guidance for this niche — editable column first, then the built-in map. */
    public function guidance(): string
    {
        $custom = trim((string) ($this->generation_guidance ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        return self::PLAYBOOK[$this->slug] ?? self::PLAYBOOK['_default'];
    }

    /** Playbook guidance for a slug (or the generic default) — for no-niche projects. */
    public static function guidanceForSlug(?string $slug): string
    {
        return self::PLAYBOOK[$slug] ?? self::PLAYBOOK['_default'];
    }
}

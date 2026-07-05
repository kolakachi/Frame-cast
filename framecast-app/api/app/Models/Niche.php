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
        'horror' => 'Cold-open on an unsettling hook, escalate the dread beat by beat, land a twist or gut-punch at the end. Ominous, sensory, patient pacing. Viewers want tension and payoff.',
        'finance' => 'Open with a myth or bold money claim, bust it, then give 2-3 concrete tips with specific numbers, and close with one clear next action. Authoritative, zero fluff. Viewers want an actionable edge.',
        'motivation' => 'Hook with a hard truth or reframe, build momentum in short punchy lines, end on an empowering call to act now. High energy, rhythmic, quotable.',
        'history' => 'Hook with a surprising fact, tell it as a tight narrative with one turning point, end on why it still matters. Stay accurate — never invent facts or dates.',
        'science' => 'Hook with a curiosity gap ("why does X happen?"), explain with one clear everyday analogy, then resolve the question. Precise and simple, no jargon dumps.',
        'product' => 'Hook on the customer\'s problem, present the product as the fix, give one proof point, end with a specific CTA. Benefit-led. Never invent testimonials, pricing, or guarantees.',
        'true-crime' => 'Cold-open on the most intriguing detail of the case, build suspense chronologically, withhold the reveal until late. Somber, factual — no invented facts.',
        'self-improvement' => 'Hook with a relatable struggle, offer a simple mindset shift or 1-2 practical steps, close by encouraging the viewer to start today. Calm, warm, practical.',
        '_default' => 'Open with a strong scroll-stopping hook, deliver the core value in tight caption-friendly lines, and end with a clear takeaway or CTA. Follow the stated tone and goal.',
    ];

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

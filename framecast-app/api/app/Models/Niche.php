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
        'motivation' => 'Hook with a hard truth or reframe, build momentum in short punchy lines, end on an empowering call to act now. High energy, rhythmic, quotable.',
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

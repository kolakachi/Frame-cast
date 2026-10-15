<?php

namespace App\Services\Ugc;

/** The menu and the director share the same production contracts. */
final class UgcTypes
{
    public const DEFINITIONS = [
        'auto' => ['Auto', 'We pick from your prompt, files and sample', 'auto', 'Choose a supported type that fits the materials.'],
        'testimonial' => ['Testimonial', 'A presenter shares a product experience', 'direct_camera', 'A personal review. Use only supplied experiences and claims.'],
        'problem_solution' => ['Problem → Solution', 'Shows the problem, then the product helping', 'story', 'Show the problem first, demonstrate the solution, then conclude. Preserve silent demonstrations.'],
        'green_screen' => ['Green screen', 'A presenter over your own page, screenshot or screen recording', 'direct_camera', 'Film the presenter waist-up against a perfectly flat pure green (#00FF00) background in a charcoal grey top, never green clothing. The real uploaded background is composited afterward. No furniture or scenery. Do not generate or read invented page text.'],
        'before_after' => ['Before / After', 'Before then after, or a split using your two uploads', 'story', 'Use supplied before and after evidence. Do not invent a transformation. Preserve both stages.'],
        'unboxing' => ['Unboxing', 'Opens the package, shows details, first impressions', 'story', 'One person on their own phone: shows the closed package, sets the phone down and opens it in view while reacting, then picks the phone up to show inside and give a first impression. The opening is done in frame by the presenter, never a product-only cutaway.'],
        'comparison' => ['Comparison', 'Compare in sequence, or split your two uploads', 'story', 'Compare supplied facts fairly. Use a generic unbranded alternative unless actual comparison assets are provided.'],
        'pov_hook' => ['POV hook', 'A bold "POV:" line over a few silent everyday moments', 'story', 'One to three short silent shots, 5 to 10 seconds in all: the presenter in an everyday moment where the product belongs (a knowing look into the lens, a reaction, the product in use), or only their hands when the moment is first-person. Never talking. Every shot is kind b_roll with an empty script; the first carries the hook as its headline, starting "POV:" and under 60 characters; a later shot may carry a short payoff headline. The words are burned on screen, so the picture has no text.'],
        'asmr' => ['ASMR', 'Product close-ups, quiet actions and native sound', 'story', 'Three to five macro shots of hands and the product, never a face. Each shot is built around one named physical sound (crack, pour, fizz, crunch, peel, tap, spray, texture) and its slow action, in order. No music. No speech unless explicitly requested as whispers.'],
        'day_in_life' => ['Day in the life', 'The product inside a daily routine', 'story', 'A coherent routine with the product naturally used across its moments. Preserve silent actions.'],
        'skit' => ['Skit', 'Two cast speakers in one scene, with a twist', 'story', 'Two cast speakers, shot/reverse-shot. Each spoken beat has speaker 1 or 2 matching the selected cast order. One speaker per shot; no overlapping dialogue. Preserve setup, exchange and payoff.'],
        'listicle' => ['Listicle', 'A numbered list of reasons or tips', 'direct_camera', 'Keep the promised number of points and their order. Say scripted numbers; never add unrequested counting.'],
        'founder_story' => ['Founder story', 'Why it was made, told with your own presenter', 'direct_camera', 'The maker tells the supplied origin story using their own selected presenter. Do not invent biography.'],
        'reaction' => ['Reaction', 'A silent reaction with an on-screen headline', 'reaction', 'One silent 5- or 10-second reaction shot of the presenter, with restrained physical movement and a short headline that sets up what they are reacting to. The headline is burned on screen, so the picture has no text.'],
    ];

    public const ROLES = ['product', 'footage', 'sample', 'background', 'left', 'right'];

    /** The speakers of a skit's spoken shots, each once; silent shots and inserts have none. */
    public static function spokenSpeakers(array $segments): array
    {
        return array_values(array_unique(array_map(fn ($s) => $s['speaker'] ?? null,
            array_filter($segments, fn ($s) => trim((string) ($s['script_text'] ?? '')) !== ''))));
    }

    /** Types that did not exist for customers before the production routes (skit needs its cast, the other two re-proving). */
    public const GATED = ['skit', 'pov_hook', 'reaction'];

    /**
     * Whether a type's new production route is open to this user (#8, 2026-10-12): staff always; customers only for types
     * listed in UGC_TYPES_LIVE, opened one by one as each passes its reviewed sample sweep. Until then a customer gets
     * yesterday's behaviour: the gated types are hidden and the others render as the closest talking-presenter ad.
     */
    public static function live(string $type, ?\App\Models\User $user = null): bool
    {
        if ($user && in_array($user->role, ['super_admin', 'platform_admin'], true)) return true;

        return in_array($type, (array) config('services.ugc.types_live', []), true);
    }

    /** Types this user cannot choose yet. */
    public static function unavailable(?\App\Models\User $user = null): array
    {
        return array_values(array_filter(self::GATED, fn ($t) => ! self::live($t, $user)));
    }

    public static function menu(?\App\Models\User $user = null): array
    {
        $off = self::unavailable($user);

        return array_map(fn ($key, $d) => ['key' => $key, 'label' => $d[0], 'description' => $d[1], 'available' => ! in_array($key, $off, true),
            'live' => self::live($key, $user)], array_keys(self::DEFINITIONS), self::DEFINITIONS);
    }

    /**
     * The production contracts the director is given, for this user: types they cannot choose are left out, and a type
     * whose new route is not open yet is described as what it is still made as, so a pitch never promises compositing,
     * a split screen or native sound that will not be made.
     */
    public static function contracts(?\App\Models\User $user = null): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $d) {
            if (in_array($key, self::unavailable($user), true)) continue;
            if (in_array($key, ['asmr', 'unboxing', 'day_in_life', 'problem_solution', 'before_after', 'comparison', 'green_screen'], true) && ! self::live($key, $user)) {
                $d[3] = 'Made as the closest version a presenter on camera can deliver: a talking presenter holding or using the product, with a few shots in sequence when the story needs them. No compositing, split screen, uploaded background or native ASMR sound yet; never promise them.';
                if ($key === 'green_screen') $d[2] = 'direct_camera';
            }
            $out[$key] = $d;
        }

        return $out;
    }

    public static function direction(string $type): string
    {
        return self::DEFINITIONS[$type][3] ?? '';
    }

    public static function format(string $type): string
    {
        return self::DEFINITIONS[$type][2] ?? 'auto';
    }

    public static function native(string $type, ?\App\Models\User $user = null): bool
    {
        return in_array($type, ['asmr', 'unboxing', 'day_in_life', 'problem_solution', 'before_after', 'comparison', 'skit', 'green_screen', 'pov_hook', 'reaction'], true)
            && self::live($type, $user);
    }
}

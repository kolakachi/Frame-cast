<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * "What style should this video be?" (owner, 2026-11-05). Users left the style on "WyvStudio chooses" because the
 * picker was a list of names. Before a new video is planned, the Creative Director asks once, offering the 3 or 4
 * styles that fit the brief as sample videos (with "See all" and "Let WyvStudio choose"). The answer, a tapped card or
 * the user's own words, sets the style; anything else lets the planner choose, as before.
 */
class StyleQuestion
{
    public const QUESTION = 'What style should this video be? Tap a style to see a sample, or let me choose.';

    /** The answer that leaves the style to the planner. */
    public const FREE = 'free';

    private const MODEL = 'claude-haiku-4-5-20251001';

    /** Styles that can be offered: the packs that have a finished sample to show. */
    public static function offerable(): array
    {
        return array_values(array_filter(StylePacks::catalogue(), fn ($p) => $p['samples'] !== []));
    }

    /**
     * Whether to ask now: a new video (no plan yet) with no style chosen, no template or saved version to start from,
     * no reference to follow, and the question not asked before in this conversation.
     */
    public static function due(object $c): bool
    {
        if (! config('create.style_question', true)) return false;
        $settings = json_decode((string) $c->settings_json, true) ?: [];
        if (($settings['output_kind'] ?? 'video') !== 'video' || ! empty($settings['style_pack']) || ! empty($settings['style_id'])
            || ! empty($settings['template_id']) || ! empty($settings['origin_conversation_id']) || self::offerable() === []) return false;
        if (DB::table('create_plans')->where('conversation_id', $c->id)->exists()) return false;
        if (DB::table('create_attachments')->where('conversation_id', $c->id)->where('purpose', 'reference')->exists()) return false;
        // A video asked for through the API (ChatGPT, Claude): the assistant picks the style itself.
        if (DB::table('create_messages')->where('conversation_id', $c->id)->where('idempotency_key', 'like', 'weave:%')->exists()) return false;

        return ! DB::table('create_messages')->where('conversation_id', $c->id)->where('idempotency_key', 'like', 'style:%')->exists();
    }

    /** The question's message key: the recommended slugs ride on it, so the conversation can show their cards. */
    public static function key(string $key, array $slugs): string
    {
        return mb_substr('style:'.$key.':'.implode(',', $slugs), 0, 255);
    }

    /** The recommended slugs carried by a question's message key. */
    public static function slugsFrom(string $messageKey): array
    {
        $known = array_column(self::offerable(), 'slug');

        return array_values(array_intersect(explode(',', (string) preg_replace('/^style:[^:]*:/', '', $messageKey)), $known));
    }

    /** The 3 or 4 offerable styles that best fit the brief: a quick read by a small model, keyword matching without one. */
    public function recommend(string $brief): array
    {
        $packs = self::offerable();
        $known = array_column($packs, 'slug');
        $key = (string) config('services.anthropic.key');
        if ($key !== '' && config('create.mode') !== 'fixture') {
            $list = collect($packs)->map(fn ($p) => '- '.$p['slug'].': '.$p['name'].'. '.$p['when'].' Best for: '.implode(', ', $p['video_types']).'.')->implode("\n");
            $ask = "A user wants a short marketing video. Pick the 3 or 4 visual styles below that would suit their brief best, best first.\n\nStyles:\n".$list
                ."\n\nBrief:\n".mb_substr($brief, 0, 3000)."\n\nReply with JSON only: {\"styles\": [\"slug\", ...]}";
            try {
                $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(25)
                    ->post('https://api.anthropic.com/v1/messages', ['model' => self::MODEL, 'max_tokens' => 120, 'messages' => [['role' => 'user', 'content' => $ask]]]);
                if ($r->successful()) {
                    PlanningCosts::call('questions', self::MODEL, (array) $r->json('usage', []));
                    $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
                    $picked = array_values(array_unique(array_intersect((array) (json_decode((string) substr($raw, (int) strpos($raw, '{'), (int) strrpos($raw, '}') - (int) strpos($raw, '{') + 1), true)['styles'] ?? []), $known)));
                    if (count($picked) >= 3) return array_slice($picked, 0, 4);
                }
            } catch (\Throwable) {
                // Fall back to matching words.
            }
        }

        return self::byWords($brief, $packs);
    }

    /** Styles ranked by how many of their "best for" words the brief uses; ties keep the catalogue's order. */
    public static function byWords(string $brief, array $packs): array
    {
        $text = mb_strtolower($brief);
        $scored = [];
        foreach ($packs as $i => $p) {
            $words = preg_split('/[^a-z]+/', mb_strtolower(implode(' ', $p['video_types']).' '.$p['when']), -1, PREG_SPLIT_NO_EMPTY);
            $hits = count(array_filter(array_unique($words), fn ($w) => strlen($w) > 3 && str_contains($text, $w)));
            $scored[] = [$hits, -$i, $p['slug']];
        }
        rsort($scored);

        return array_slice(array_column($scored, 2), 0, 4);
    }

    /** A style slug, FREE (let WyvStudio choose) or null (not an answer about style) for the user's reply. */
    public function answer(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '' || preg_match('/\b(you choose|you pick|your choice|let (wyvstudio|you|wyv) (choose|pick|decide)|surprise me|whatever|don\'?t mind|no preference|skip)\b/', $t)) return self::FREE;
        $packs = self::offerable();
        foreach ($packs as $p) {
            if (str_contains($t, mb_strtolower($p['name'])) || str_contains($t, $p['slug'])) return $p['slug'];
        }
        $key = (string) config('services.anthropic.key');
        if ($key === '' || config('create.mode') === 'fixture') return null;
        $list = collect($packs)->map(fn ($p) => '- '.$p['slug'].': '.$p['name'].' ('.$p['look'].')')->implode("\n");
        $ask = "A user was asked which visual style their video should have. Styles:\n".$list."\n\nTheir answer: \"".mb_substr($text, 0, 600)."\"\n"
            .'Reply with JSON only: {"style": "<slug>" | "free" (they leave it to us) | null (their answer is not about the style)}';
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(20)
                ->post('https://api.anthropic.com/v1/messages', ['model' => self::MODEL, 'max_tokens' => 60, 'messages' => [['role' => 'user', 'content' => $ask]]]);
            if (! $r->successful()) return null;
            PlanningCosts::call('questions', self::MODEL, (array) $r->json('usage', []));
            $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $s = json_decode((string) substr($raw, (int) strpos($raw, '{'), (int) strrpos($raw, '}') - (int) strpos($raw, '{') + 1), true)['style'] ?? null;

            return $s === self::FREE || in_array($s, array_column($packs, 'slug'), true) ? $s : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

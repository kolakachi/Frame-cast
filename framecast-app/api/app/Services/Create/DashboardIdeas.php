<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * "Next videos for you" (D8, 2026-10-09): three ideas for the dashboard, written from the workspace's own videos.
 * More like your last (a new angle on the latest video), a format not tried yet, and one quick post. Free to the user.
 * One model call per new video or new week (about $0.004): kept until either changes, never rewritten on a visit,
 * with no refresh, so the cost follows videos made rather than page views.
 */
class DashboardIdeas
{
    /** The dashboard's card types an idea can start from (a format playbook id each). */
    public const FORMATS = ['offer_ad' => 'a short product ad', 'launch_promo' => 'a launch teaser', 'testimonial' => 'a UGC testimonial spoken to camera',
        'explainer' => 'a short explainer', 'listicle' => 'a quick tips video'];

    /** @return list<array{kind: string, title: string, why: string, format: string, brief: string}> */
    public function for(int $workspaceId): array
    {
        $latestWeave = DB::table('create_conversations')->where('workspace_id', $workspaceId)->whereNotNull('head_revision_id')->orderByDesc('created_at')->value('id');
        $latestClassic = DB::table('projects')->where('workspace_id', $workspaceId)->max('id');
        if (! $latestWeave && ! $latestClassic) return [];
        $key = 'dash-ideas:'.$workspaceId.':'.hash('xxh3', $latestWeave.'|'.$latestClassic.'|'.now()->format('o-W'));
        if (($kept = Cache::get($key)) !== null) return $kept;
        // One writer at a time per workspace; a second visit meanwhile shows nothing rather than paying twice.
        $lock = Cache::lock('dash-ideas-lock:'.$workspaceId, 60);
        if (! $lock->get()) return [];
        try {
            $ideas = $this->write($workspaceId);
            // Nothing written (no key, an error): try again in an hour, not on every visit.
            Cache::put($key, $ideas, $ideas ? now()->addDays(8) : now()->addHour());
            return $ideas;
        } finally {
            $lock->release();
        }
    }

    private function write(int $workspaceId): array
    {
        if ((string) config('services.anthropic.key') === '' || config('create.mode') === 'fixture') return [];
        $briefs = DB::table('create_conversations')->where('workspace_id', $workspaceId)->whereNotNull('head_revision_id')->orderByDesc('created_at')->limit(8)->get(['id', 'title', 'settings_json'])
            ->map(function ($c) {
                $plan = json_decode((string) DB::table('create_plans')->where('conversation_id', $c->id)->orderByDesc('created_at')->value('plan_json'), true) ?: [];
                return ['title' => (string) $c->title, 'format' => json_decode((string) $c->settings_json, true)['format'] ?? ($plan['playbook']['id'] ?? null),
                    'brief' => mb_substr((string) DB::table('create_messages')->where('conversation_id', $c->id)->where('role', 'user')->orderBy('sequence')->value('content'), 0, 400)];
            })->all();
        $classic = DB::table('projects')->where('workspace_id', $workspaceId)->orderByDesc('id')->limit(4)->get(['title', 'source_content_raw'])
            ->map(fn ($p) => ['title' => (string) $p->title, 'brief' => mb_substr((string) $p->source_content_raw, 0, 300)])->all();
        $used = array_values(array_unique(array_filter(array_column($briefs, 'format'))));
        $industry = DB::table('workspaces')->where('id', $workspaceId)->value('industry');
        // The format not tried yet: the first card type this workspace has not used.
        $untried = collect(array_keys(self::FORMATS))->first(fn ($f) => ! in_array($f, $used, true)) ?? 'listicle';
        $brand = trim((string) DB::table('brand_kits')->where('workspace_id', $workspaceId)->orderBy('id')->value('name'));
        $formats = implode("\n", array_map(fn ($id, $what) => "- {$id}: {$what}", array_keys(self::FORMATS), self::FORMATS));

        $ask = "Suggest three next short videos for this business, from the videos it has made (newest first, below as JSON).\n"
            ."1. kind \"more\": a new angle on its newest video (same product or subject), in a different format from that video's.\n"
            ."2. kind \"new_format\": a video in format \"{$untried}\", which it has not tried, about its main product or subject.\n"
            ."3. kind \"quick\": a quick, easy video to post this week, in any format.\n"
            ."Formats:\n{$formats}\n"
            ."Use only what the business wrote: its products, offers, audience and names, spelled exactly as written. Never invent a price, discount, number, date, result or claim: where one is needed and was not given, write a short [bracketed placeholder]. Plain words, no emojis or hashtags. Write why to the business as \"you\", naming formats in plain words (a tips video, an explainer), never by their ids.\n"
            .'Reply with JSON only: {"ideas": [{"kind": "more|new_format|quick", "format": "<format id>", "title": "<the video, under 12 words>", "why": "<why this one, one short sentence under 18 words>", "brief": "<what the video is, 1 to 3 sentences, under 300 characters, written as the user would describe it>"}]}'
            ."\n\n".json_encode(['brand' => $brand ?: null, 'industry' => $industry ? (Industry::LIST[$industry] ?? null) : null, 'videos' => $briefs, 'classic_videos' => $classic], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(45)
            ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'), 'max_tokens' => 1400, 'messages' => [['role' => 'user', 'content' => $ask]]]);
        if (! $r->successful()) return [];
        $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $start = strpos($raw, '{');
        $json = $start === false ? null : json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true);
        $str = fn ($v, $n) => is_string($v) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $v)), 0, $n) : '';
        // A line cut at a word, never mid-word, when the model runs long.
        $words = fn ($v, $n) => mb_strlen($t = $str($v, 1000)) <= $n ? $t : rtrim(mb_substr($t, 0, mb_strrpos(mb_substr($t, 0, $n), ' ') ?: $n), ' ,;:').'…';
        $kinds = ['more', 'new_format', 'quick'];
        // Each idea's kind is its place in the reply (the order asked for) when the model leaves it out or misnames it.
        return collect(array_values(array_filter((array) ($json['ideas'] ?? []), 'is_array')))->map(fn ($i, $n) => [
            'kind' => in_array($i['kind'] ?? '', $kinds, true) ? $i['kind'] : ($kinds[$n] ?? 'quick'),
            'format' => isset(self::FORMATS[$i['format'] ?? '']) ? $i['format'] : 'offer_ad',
            'title' => $words($i['title'] ?? '', 90), 'why' => $words($i['why'] ?? '', 150), 'brief' => $str($i['brief'] ?? '', 400),
        ])->filter(fn ($i) => $i['title'] !== '' && $i['brief'] !== '')->unique('kind')->take(3)->values()->all();
    }
}

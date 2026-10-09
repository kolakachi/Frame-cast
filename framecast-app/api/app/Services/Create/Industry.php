<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * What a workspace sells (D5, 2026-10-09). The dashboard orders its video cards and words their examples by it. A
 * workspace from before the new onboarding has it learned once from what it has made: its brand's name and the
 * briefs and titles of its videos. Too little to go on leaves it unset, to try again after more videos.
 */
class Industry
{
    public const LIST = [
        'beauty' => 'Beauty and skincare', 'food' => 'Food and drink', 'fashion' => 'Fashion and accessories', 'home' => 'Home and furniture',
        'health' => 'Health and fitness', 'tech' => 'Apps and software', 'education' => 'Courses and coaching', 'local' => 'Local services',
        'agency' => 'An agency or marketer making videos for clients', 'other' => 'Anything else',
    ];

    /** What the workspace has written: brand names, its latest briefs and its video titles. */
    public static function material(int $workspaceId): string
    {
        $lines = array_merge(
            DB::table('brand_kits')->where('workspace_id', $workspaceId)->pluck('name')->all(),
            DB::table('create_conversations')->where('workspace_id', $workspaceId)->orderByDesc('created_at')->limit(12)->pluck('id')
                ->map(fn ($id) => mb_substr((string) DB::table('create_messages')->where('conversation_id', $id)->where('role', 'user')->orderBy('sequence')->value('content'), 0, 300))->all(),
            DB::table('projects')->where('workspace_id', $workspaceId)->orderByDesc('created_at')->limit(12)->get(['title', 'source_content_raw'])
                ->map(fn ($p) => trim($p->title.' '.mb_substr((string) $p->source_content_raw, 0, 200)))->all(),
        );
        return trim(implode("\n", array_filter(array_map(fn ($l) => trim((string) $l), $lines))));
    }

    /** The learned industry id, or null when there is too little to go on or the model could not say. */
    public function learn(int $workspaceId): ?string
    {
        $material = self::material($workspaceId);
        if (mb_strlen($material) < 60 || (string) config('services.anthropic.key') === '' || config('create.mode') === 'fixture') return null;
        $list = implode("\n", array_map(fn ($id, $what) => "- {$id}: {$what}", array_keys(self::LIST), self::LIST));
        $ask = "Below is what a business wrote while making short marketing videos: brand names, video briefs and titles. Which one of these best describes what the business sells?\n{$list}\n"
            ."Pick the business's own industry, not the topic of one video. Pick agency only when the videos are clearly for several unrelated brands. Pick other when nothing fits or it cannot be told.\n"
            .'Reply with JSON only: {"industry": "<id>"}'."\n\n".mb_substr($material, 0, 6000);
        $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(30)
            ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'), 'max_tokens' => 200, 'messages' => [['role' => 'user', 'content' => $ask]]]);
        if (! $r->successful()) return null;
        $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $id = preg_match('/"industry"\s*:\s*"([a-z]+)"/', $raw, $m) ? $m[1] : null;
        return isset(self::LIST[$id]) ? $id : null;
    }
}

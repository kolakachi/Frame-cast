<?php

namespace App\Services\Create;

use App\Models\User;
use App\Services\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * "More ways" in the directions drawer (FS1): three new directions for the same brief, different from every one shown,
 * each made with the user's own files. One call to the planner model, billed like planning (half its real cost, never
 * free). The new directions join the plan; picking one re-plans it like any other.
 */
class DirectionService
{
    public const MORE = 3;
    public const MAX_EXTRA = 9;
    public const MIN_CREDITS = 10;

    public function more(User $user, string $conversationId, string $planId): array
    {
        $conversations = app(ConversationService::class);
        $conversations->authorize($user, true);
        $c = $conversations->conversation($user, $conversationId);
        $row = DB::table('create_plans')->where('conversation_id', $c->id)->where('id', $planId)->firstOrFail();
        $plan = json_decode((string) $row->plan_json, true) ?: [];
        abort_unless(isset($plan['concept']['idea']), 422, 'This plan has no directions.');
        $shown = array_merge([$plan['concept']], $plan['concept']['alternatives'] ?? [], $plan['concept']['more'] ?? []);
        abort_if(count($plan['concept']['more'] ?? []) >= self::MAX_EXTRA, 422, 'That is every way I would make this. Describe your own below.');
        $available = (int) $conversations->creditAvailability($user)['available'];
        abort_if($available < self::MIN_CREDITS, 402, 'Top up to see more ways: this takes a few credits and you have '.max(0, $available).' available.');
        abort_if((string) config('services.anthropic.key') === '' || config('create.mode') === 'fixture', 503, 'More ways are not available here.');

        $files = DB::table('create_attachments')->join('assets', 'assets.id', '=', 'create_attachments.asset_id')->where('create_attachments.conversation_id', $c->id)
            ->where('create_attachments.purpose', 'source')->get(['assets.title', 'create_attachments.notes_json'])
            ->map(fn ($f) => ['title' => $f->title] + array_intersect_key(json_decode((string) $f->notes_json, true) ?: [], array_flip(['kind', 'use'])))->all();
        $brief = DB::table('create_messages')->where('conversation_id', $c->id)->orderBy('sequence')->get(['role', 'content'])->map(fn ($m) => (array) $m)->all();
        $model = preg_replace('#^anthropic/#', '', (string) config('create.planner_model'));
        $ask = "Brief and conversation, files the user gave to work with, the format playbooks, and the directions already shown are below as JSON.\n"
            ."Propose ".self::MORE." NEW directions for this video, each genuinely different from every direction shown (a different idea, opening, look and, where it fits, format). Every direction is made with the user's files: say how in uses (or why one is left out). Never invent prices, numbers, claims or endorsements.\n"
            .'Reply with JSON only: {"directions": [{"name": string (under 6 words), "idea": string (under 25 words), "hook": string (the first 2 s), "look": string, "swatches": [three #rrggbb], "format": string (a playbook id), "structure": string, "opening": string, "ending": string, "uses": string}]}'
            ."\n\n".json_encode(['messages' => $brief, 'files' => $files, 'format_playbooks' => FormatPlaybooks::catalogue()['playbooks'],
                'shown' => array_map(fn ($d) => array_intersect_key($d, array_flip(['name', 'idea', 'format'])), $shown)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(90)
            ->post('https://api.anthropic.com/v1/messages', ['model' => $model, 'max_tokens' => 2000, 'messages' => [['role' => 'user', 'content' => $ask]]]);
        if (! $r->successful()) {
            \App\Services\Vendors\VendorAlerts::observe('anthropic', $r->body(), $r->status());
            abort(503, 'Could not think of more ways just now. Try again in a moment.');
        }
        $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $start = strpos($raw, '{');
        $json = $start === false ? null : json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true);
        $names = array_map(fn ($d) => mb_strtolower((string) ($d['name'] ?? '')), $shown);
        $new = array_values(array_filter(array_map([FormatPlaybooks::class, 'direction'], array_slice((array) ($json['directions'] ?? []), 0, self::MORE)),
            fn ($d) => isset($d['idea'], $d['name']) && ! in_array(mb_strtolower($d['name']), $names, true)));
        abort_unless($new, 502, 'Could not think of more ways just now. Try again in a moment.');

        // Billed like planning: half the call's real cost, from the balance (never more than is left).
        PlanningCosts::begin('directions:'.$planId);
        PlanningCosts::call('directions', $model, (array) $r->json('usage', []));
        $credits = CostEstimate::planningCharge((int) ceil(array_sum(PlanningCosts::take('directions:'.$planId)) / 4000));
        PlanningCosts::end();
        $charged = 0;
        if ($credits > 0 && ($take = min($credits, $available)) > 0 && app(CreditService::class)->deduct((int) $user->workspace_id, $take, 'create_directions', ['conversation_id' => $c->id, 'plan_id' => $planId])) $charged = $take;

        return DB::transaction(function () use ($planId, $new, $charged) {
            $fresh = json_decode((string) DB::table('create_plans')->where('id', $planId)->lockForUpdate()->value('plan_json'), true) ?: [];
            $fresh['concept']['more'] = array_values(array_slice(array_merge($fresh['concept']['more'] ?? [], $new), 0, self::MAX_EXTRA));
            DB::table('create_plans')->where('id', $planId)->update(['plan_json' => json_encode($fresh), 'updated_at' => now()]);
            return ['directions' => $new, 'charged' => $charged];
        });
    }
}

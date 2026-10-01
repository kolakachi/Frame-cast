<?php
namespace App\Services\Create;

use App\Models\{Asset, User};
use App\Services\Create\Planning\{AnthropicPlanner, OfflinePlanner, Planner, ReplicatePlanner};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The plan turn. A plan is free to the user: WyvStudio pays for the single
 * planning call, bounded by a daily limit. The model proposes; this service
 * decides what is allowed (only supplied files, only known tools, prices from
 * the catalogue) and the quote binds to the plan the user approved.
 */
class PlanService
{
    public function __construct(private ConversationService $conversations) {}

    public function planner(): Planner
    {
        $choice = config('create.mode') === 'fixture' ? 'offline' : (string) config('create.planner', 'offline');
        return match ($choice) {
            'replicate' => new ReplicatePlanner((string) config('create.planner_model', 'anthropic/claude-sonnet-5'), (string) config('services.replicate.api_token')),
            'anthropic' => new AnthropicPlanner((string) config('create.planner_model', 'claude-opus-5-5'), (string) config('services.anthropic.key')),
            default => new OfflinePlanner,
        };
    }

    public function propose(User $user, string $id, int $version, string $key): array
    {
        $this->conversations->authorize($user, true);
        $c = $this->conversations->conversation($user, $id);
        $hash = hash('sha256', $id.'|'.$version);
        $old = DB::table('create_plans')->where('conversation_id', $id)->where('idempotency_key', $key)->first();
        if ($old) {
            abort_unless(hash_equals($old->request_hash, $hash), 409, 'This request key already belongs to a different plan.');
            return $this->present($old, $c);
        }
        abort_if($c->archived_at, 409, 'Restore this conversation before planning.');
        abort_unless((int) $c->version === $version, 409, 'Conversation changed. Refresh before planning.');
        $briefs = DB::table('create_messages')->where('conversation_id', $id)->where('role', 'user')->orderBy('sequence')->get(['content', 'sequence']);
        abort_if($briefs->isEmpty(), 422, 'Add a brief first.');
        $today = DB::table('create_plans')->join('create_conversations', 'create_conversations.id', '=', 'create_plans.conversation_id')
            ->where('create_conversations.workspace_id', $user->workspace_id)->where('create_plans.created_at', '>=', now()->startOfDay())->count();
        abort_if($today >= (int) config('create.plan_daily_limit', 40), 429, 'Today\'s planning limit is reached. Plans reset at midnight.');

        $context = $this->context($user, $c);
        try {
            $result = $this->planner()->plan($context);
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'The planner could not make a plan just now. Nothing was charged; try again.');
        }
        $plan = $this->normalize($result['plan'], $context, (int) $user->workspace_id);

        return DB::transaction(function () use ($user, $id, $version, $key, $hash, $plan, $result, $briefs) {
            $c = $this->conversations->conversation($user, $id, true);
            abort_unless((int) $c->version === $version, 409, 'The conversation changed while planning. Plan again.');
            $messageId = (string) Str::uuid(); $planId = (string) Str::uuid(); $next = $c->version + 1;
            DB::table('create_messages')->insert(['id' => $messageId, 'conversation_id' => $id, 'role' => 'assistant', 'content' => $plan['summary'],
                'idempotency_key' => 'plan:'.$planId, 'request_hash' => hash('sha256', $plan['summary']), 'sequence' => $next, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('create_plans')->where('conversation_id', $id)->where('status', 'proposed')->update(['status' => 'superseded', 'updated_at' => now()]);
            DB::table('create_plans')->insert(['id' => $planId, 'conversation_id' => $id, 'message_id' => $messageId, 'brief_sequence' => (int) $briefs->last()->sequence,
                'idempotency_key' => $key, 'request_hash' => $hash, 'provider' => mb_substr($result['provider'], 0, 120), 'plan_json' => json_encode($plan),
                'usage_json' => $result['usage'] ? json_encode($result['usage']) : null, 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('create_conversations')->where('id', $id)->update(['version' => $next, 'updated_at' => now()]);
            return $this->present(DB::table('create_plans')->where('id', $planId)->first(), DB::table('create_conversations')->where('id', $id)->first());
        });
    }

    /** Save the user's edits to callouts, choices and kept items. */
    public function select(User $user, string $id, string $planId, int $version, array $input): array
    {
        $this->conversations->authorize($user, true);
        return DB::transaction(function () use ($user, $id, $planId, $version, $input) {
            $c = $this->conversations->conversation($user, $id, true);
            abort_if($c->archived_at || (int) $c->version !== $version, 409, 'Conversation changed. Refresh before editing the plan.');
            $row = DB::table('create_plans')->where('conversation_id', $id)->where('id', $planId)->lockForUpdate()->firstOrFail();
            abort_unless($row->status === 'proposed' && ! $this->stale($row, $c), 409, 'This plan is out of date. Plan again from the latest brief.');
            $plan = json_decode($row->plan_json, true);
            $sel = $plan['selections'];
            if (array_key_exists('callouts', $input)) {
                $sel['callouts'] = array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 120), (array) $input['callouts']), fn ($t) => $t !== ''));
                abort_if(count($sel['callouts']) > 6, 422, 'Use at most six callouts.');
            }
            if (array_key_exists('narration', $input)) {
                $sel['narration'] = array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 160), (array) $input['narration']), fn ($t) => $t !== ''));
                abort_if(count($sel['narration']) > 8, 422, 'Use at most eight narration lines.');
            }
            if (array_key_exists('style', $input)) {
                // The user may switch between the routes this conversation supports.
                $want = (array) $input['style'];
                $ctx = $this->context($user, $c);
                $picked = StylePacks::route(['route' => $want['route'] ?? null, 'pack' => $want['pack'] ?? null, 'why' => 'Chosen by you'], [...$ctx, 'settings' => [...$ctx['settings'], 'style_pack' => null], 'house_style' => ($want['route'] ?? null) === 'saved' ? $ctx['house_style'] : null]);
                abort_unless($picked['route'] === ($want['route'] ?? null), 422, 'That style is not available for this brief.');
                $sel['style'] = $picked;
            }
            if (array_key_exists('voice', $input)) {
                abort_unless(\App\Services\Generation\TTS\GeminiVoices::isGeminiVoice((string) $input['voice']) || $input['voice'] === 'clone', 422, 'Choose one of the listed voices.');
                $sel['voice'] = (string) $input['voice'];
            }
            foreach ((array) ($input['choices'] ?? []) as $decision => $option) {
                $d = collect($plan['decisions'])->firstWhere('id', $decision);
                abort_unless($d && collect($d['options'])->firstWhere('id', $option), 422, 'Choose one of the offered options.');
                $sel['choices'][$decision] = $option;
            }
            if (array_key_exists('kept', $input)) {
                $kept = array_values((array) $input['kept']);
                abort_if(array_diff($kept, $plan['kept_as_is']) !== [], 422, 'Only listed items can be kept as-is.');
                $sel['kept'] = $kept;
            }
            $plan['selections'] = $sel;
            $plan['credits'] = $this->credits($plan);
            DB::table('create_plans')->where('id', $planId)->update(['plan_json' => json_encode($plan), 'updated_at' => now()]);
            DB::table('create_conversations')->where('id', $id)->update(['version' => $c->version + 1, 'updated_at' => now()]);
            return $this->present(DB::table('create_plans')->where('id', $planId)->first(), DB::table('create_conversations')->where('id', $id)->first());
        });
    }

    /** The approved plan a quote binds to, or null when there is no current plan. */
    public static function forQuote(object $c): ?array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('create_plans')) return null;
        $row = DB::table('create_plans')->where('conversation_id', $c->id)->where('status', 'proposed')->orderByDesc('created_at')->first();
        if (! $row || (new self(app(ConversationService::class)))->stale($row, $c)) return null;
        $p = json_decode($row->plan_json, true); $s = $p['selections'];
        return ['plan_id' => $row->id, 'summary' => $p['summary'], 'reused' => $p['reused'], 'scenes' => $p['scenes'],
            'on_screen_copy' => $s['callouts'], 'narration' => $s['narration'] ?? [], 'voice' => $s['voice'] ?? null, 'kept_as_is' => $s['kept'],
            'choices' => collect($p['decisions'])->map(fn ($d) => ['question' => $d['question'], 'chosen' => collect($d['options'])->firstWhere('id', $s['choices'][$d['id']] ?? null)['label'] ?? null])->all(),
            'media' => $p['media'], 'left_out' => $p['left_out'], 'style_route' => $s['style'] ?? $p['style'] ?? null];
    }

    public function stale(object $plan, object $c): bool
    {
        $last = DB::table('create_messages')->where('conversation_id', $c->id)->where('role', 'user')->max('sequence');
        return (int) $last !== (int) $plan->brief_sequence;
    }

    public function present(object $row, object $c): array
    {
        return ['id' => $row->id, 'message_id' => $row->message_id, 'status' => $row->status, 'provider' => $row->provider,
            'stale' => $row->status === 'proposed' && $this->stale($row, $c), 'plan' => json_decode($row->plan_json, true), 'created_at' => $row->created_at];
    }

    /** What a reference teaches, compact for model context; null when it was never studied. */
    /**
     * Lines whose words are not in the user's brief, approved facts or attached
     * pages. They are not blocked (a headline needs craft), but the plan card
     * marks them so the user sees new wording before approving it.
     */
    public static function newWording(array $lines, array $ctx): array
    {
        $words = fn (string $t) => array_map(fn ($w) => preg_replace('/(ies|es|s)$/', '', $w), preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t), -1, PREG_SPLIT_NO_EMPTY));
        $corpus = implode(' ', [
            ...array_map(fn ($m) => (string) ($m['content'] ?? ''), array_filter($ctx['messages'] ?? [], fn ($m) => ($m['role'] ?? '') === 'user')),
            ...($ctx['approved_facts'] ?? []),
            ...collect($ctx['files'] ?? [])->flatMap(fn ($f) => $f['reference']['page_claims_not_approved'] ?? [])->all(),
        ]);
        $known = array_flip($words($corpus));
        $common = array_flip(['a', 'an', 'the', 'and', 'or', 'to', 'of', 'in', 'on', 'for', 'with', 'your', 'you', 'it', 'is', 'are', 'be', 'that', 'this', 'one', 'no', 'not', 'from', 'into', 'at', 'by', 'we', 'our', 'get', 'make', 'now', 'just', 'all', 'any', 'more', 'how', 'what', 'why', 'two', 'three', 'four', 'five', 'six', 'ready', 'try', 'start', 'today', 'meet', 'say', 'hello', 'got', 'need']);
        return array_values(array_filter(array_unique($lines), function ($line) use ($words, $known, $common) {
            foreach ($words($line) as $w) if (mb_strlen($w) > 2 && ! isset($known[$w]) && ! isset($common[$w]) && ! ctype_digit($w)) return true;
            return false;
        }));
    }

    public static function referenceBrief(Asset $asset): ?array
    {
        $a = data_get($asset->metadata_json, 'reference_analysis');
        if (! is_array($a)) return null;
        return array_filter(['from' => data_get($asset->metadata_json, 'reference_source.platform'), 'duration_seconds' => $a['duration_seconds'] ?? null,
            'shots' => $a['shots'] ?? null, 'average_shot_seconds' => $a['average_shot_seconds'] ?? null,
            'speech' => isset($a['transcript']) ? mb_substr((string) $a['transcript'], 0, 600) : null, 'notes' => $a['notes'] ?? null,
            // From a web page: claims the page makes. Not approved; only approved_facts may go on screen.
            'page_claims_not_approved' => ! empty($a['suggested_claims']) ? array_column($a['suggested_claims'], 'text') : null], fn ($v) => $v !== null);
    }

    private function context(User $user, object $c): array
    {
        $settings = json_decode($c->settings_json, true) ?: [];
        $files = DB::table('create_attachments')->where('conversation_id', $c->id)->orderBy('asset_id')->get()->map(function ($a) use ($user) {
            $asset = Asset::where('workspace_id', $user->workspace_id)->find($a->asset_id);
            return $asset ? ['asset_id' => (int) $asset->id, 'title' => (string) $asset->title, 'asset_type' => $asset->asset_type, 'purpose' => $a->purpose,
                'duration_seconds' => $asset->duration_seconds, 'dimensions' => $asset->dimensions_json,
                'reference' => $a->purpose === 'reference' ? self::referenceBrief($asset) : null] : null;
        })->filter()->values()->all();
        return [
            'messages' => DB::table('create_messages')->where('conversation_id', $c->id)->orderBy('sequence')->get(['role', 'content'])->map(fn ($m) => (array) $m)->all(),
            // Text and colour fields of the current version; a request that only changes these is free.
            'current_variables' => ($head = $c->head_revision_id ? DB::table('composition_revisions')->where('id', $c->head_revision_id)->value('bundle_json') : null)
                ? array_map(fn ($d) => ['id' => $d['id'], 'type' => $d['type'], 'label' => $d['label'] ?? $d['id'], 'current' => $d['default'] ?? null],
                    array_values(array_filter(CompositionVariables::declarations((string) (json_decode($head, true)['index.html'] ?? '')), fn ($d) => in_array($d['type'] ?? '', ['string', 'color'], true))))
                : [],
            // Voices the narration may use: the catalogue by character, plus the workspace's own clone.
            'voices' => array_merge(array_map(fn ($k) => ['key' => $k, 'character' => \App\Services\Generation\TTS\GeminiVoices::VOICES[$k], 'gender' => \App\Services\Generation\TTS\GeminiVoices::gender($k)], array_keys(\App\Services\Generation\TTS\GeminiVoices::VOICES)),
                \Illuminate\Support\Facades\Schema::hasTable('voice_profiles') && DB::table('voice_profiles')->where('workspace_id', $user->workspace_id)->where('is_cloned', true)->exists() ? [['key' => 'clone', 'character' => "The workspace's own cloned voice", 'gender' => '']] : []),
            'files' => $files, 'settings' => $settings, 'house_style' => StyleService::brief($settings['style_id'] ?? null, (int) $user->workspace_id), 'approved_facts' => $settings['approved_facts'] ?? [],
            // Built-in style packs to start from, and the ones this workspace used last, so the planner varies them.
            'style_packs' => StylePacks::catalogue(),
            // A pack the user pinned: the planner writes the scenes inside its rules.
            'pinned_style_rules' => StylePacks::exists($settings['style_pack'] ?? null) ? (string) file_get_contents(StylePacks::dir().'/'.$settings['style_pack'].'/STYLE.md') : null,
            'recent_style_packs' => DB::table('composition_runs')->where('workspace_id', $user->workspace_id)->orderByDesc('created_at')->limit(6)->pluck('input_json')
                ->map(fn ($j) => data_get(json_decode($j, true), 'style_pack.slug'))->filter()->unique()->take(3)->values()->all(),
            'tools' => CapabilityCatalogue::forWorkspace((int) $user->workspace_id), 'brand_kits' => CapabilityCatalogue::brandKits((int) $user->workspace_id),
            // The user's edits to the last plan are their decisions; a new plan starts from them.
            'previous_plan' => ($prev = DB::table('create_plans')->where('conversation_id', $c->id)->orderByDesc('created_at')->first())
                ? ['summary' => json_decode($prev->plan_json, true)['summary'] ?? '', 'approved_copy' => json_decode($prev->plan_json, true)['selections']['callouts'] ?? [],
                    'kept_as_is' => json_decode($prev->plan_json, true)['selections']['kept'] ?? []] : null,
        ];
    }

    public function normalize(array $raw, array $ctx, int $workspaceId): array
    {
        // Cut long text at a word boundary, never mid-word.
        $str = function ($v, int $n) {
            $t = trim(is_string($v) ? $v : '');
            if (mb_strlen($t) <= $n) return $t;
            $cut = mb_substr($t, 0, $n - 1);
            $space = mb_strrpos($cut, ' ');
            return rtrim($space > $n * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,;:-").'…';
        };
        $slug = fn ($v) => mb_substr(preg_replace('/[^a-z0-9_-]/', '', strtolower(is_string($v) ? $v : '')), 0, 32);
        $summary = $str($raw['summary'] ?? '', 600);
        abort_if($summary === '', 502, 'The planner returned an empty plan. Nothing was charged; try again.');
        $image = ($ctx['settings']['output_kind'] ?? 'video') === 'image';
        $duration = (float) ($ctx['settings']['duration_seconds'] ?? 15);
        $sources = collect($ctx['files'])->where('purpose', 'source')->keyBy('asset_id');
        $reused = collect((array) ($raw['reused'] ?? []))->filter(fn ($r) => is_array($r) && $sources->has((int) ($r['asset_id'] ?? 0)))
            ->map(fn ($r) => ['asset_id' => (int) $r['asset_id'], 'title' => $sources[(int) $r['asset_id']]['title'], 'use' => $str($r['use'] ?? '', 120)])->unique('asset_id')->values()->all();
        $scenes = $image ? [] : collect((array) ($raw['scenes'] ?? []))->filter(fn ($s) => is_array($s))->map(fn ($s) => [
            'label' => $str($s['label'] ?? '', 40), 'start' => round(max(0, min($duration, (float) ($s['start'] ?? 0))), 1),
            'end' => round(max(0, min($duration, (float) ($s['end'] ?? 0))), 1), 'idea' => $str($s['idea'] ?? '', 160),
            // The director's plan: what is on screen at each end of the beat, and what the viewer must understand, in order.
            'state_in' => $str($s['state_in'] ?? '', 120), 'state_out' => $str($s['state_out'] ?? '', 120),
            'reads' => collect((array) ($s['reads'] ?? []))->map(fn ($r) => $str($r, 90))->filter()->take(4)->values()->all(),
        ])->filter(fn ($s) => $s['label'] !== '' && $s['end'] > $s['start'])->take(8)->values()->all();
        $callouts = collect((array) ($raw['callouts'] ?? []))->map(fn ($t) => $str($t, 120))->filter()->unique()->take(6)->values()->all();
        $known = collect(CapabilityCatalogue::forWorkspace($workspaceId))->keyBy('kind');
        $decisions = collect((array) ($raw['decisions'] ?? []))->filter(fn ($d) => is_array($d))->map(function ($d) use ($str, $slug, $known) {
            $options = collect((array) ($d['options'] ?? []))->filter(fn ($o) => is_array($o))->map(function ($o) use ($str, $slug, $known) {
                $media = ($o['kind'] ?? '') === 'media' && $known->has($o['tool'] ?? '');
                return ['id' => $slug($o['id'] ?? $o['label'] ?? ''), 'label' => $str($o['label'] ?? '', 60), 'detail' => $str($o['detail'] ?? '', 160),
                    'kind' => $media ? 'media' : 'included', 'tool' => $media ? $o['tool'] : null, 'credits' => $media ? (int) $known[$o['tool']]['credits'] : 0];
            })->filter(fn ($o) => $o['id'] !== '' && $o['label'] !== '')->unique('id')->take(3)->values()->all();
            return ['id' => $slug($d['id'] ?? $d['question'] ?? ''), 'question' => $str($d['question'] ?? '', 120), 'options' => $options];
        })->filter(fn ($d) => $d['id'] !== '' && $d['question'] !== '' && count($d['options']) >= 2)->unique('id')->take(3)->values()->all();
        $kept = collect((array) ($raw['kept_as_is'] ?? []))->map(fn ($t) => $str($t, 80))->filter()->unique()->take(8)->values()->all();
        $media = collect((array) ($raw['media'] ?? []))->filter(fn ($m) => is_array($m) && $known->has($m['kind'] ?? ''))
            ->map(fn ($m) => ['kind' => $m['kind'], 'description' => $str($m['description'] ?? '', 200), 'credits' => (int) $known[$m['kind']]['credits']])->take(6)
            // The talking shot is made from the poses and the narration, so it is always bought after them.
            ->sortBy(fn ($m) => $m['kind'] === 'talking_shot' ? 1 : 0, SORT_NUMERIC, false)->values()->all();
        // The spoken script: short lines, sized to the video, only when the video should speak.
        $silent = ($ctx['settings']['audio'] ?? 'original') === 'silent';
        // Measured: the catalogue voices speak about 2 words a second with pauses; leave 1.5 s at the end.
        $maxWords = (int) round(max(4, (int) ($ctx['settings']['duration_seconds'] ?? 15) - 1.5) * 2.0);
        $narration = [];
        foreach ((array) ($raw['narration'] ?? []) as $line) {
            $line = $str($line, 160);
            if ($line === '' || count($narration) >= 8) continue;
            $words = str_word_count(implode(' ', [...$narration, $line]));
            if ($words > $maxWords) break;
            $narration[] = $line;
        }
        if ($silent) $narration = [];
        $voiceKeys = array_column($ctx['voices'] ?? [], 'key');
        $voice = in_array($raw['voice'] ?? null, $voiceKeys, true) ? $raw['voice'] : \App\Services\Generation\TTS\GeminiVoices::DEFAULT_VOICE;
        // A script needs a voice to say it: make sure the plan buys one.
        if ($narration && ! collect($media)->contains(fn ($m) => in_array($m['kind'], ['voiceover', 'cloned_voiceover'], true))) {
            $kind = $voice === 'clone' && $known->has('cloned_voiceover') ? 'cloned_voiceover' : 'voiceover';
            if ($known->has($kind)) $media[] = ['kind' => $kind, 'description' => 'Narration of the approved script', 'credits' => (int) $known[$kind]['credits']];
        }
        $style = StylePacks::route(is_array($raw['style'] ?? null) ? $raw['style'] : [], $ctx);
        $plan = ['summary' => $summary, 'reused' => $reused, 'scenes' => $scenes, 'callouts' => $callouts, 'decisions' => $decisions, 'narration' => $narration, 'voice' => $voice,
            'kept_as_is' => $kept, 'media' => $media, 'left_out' => $str($raw['left_out'] ?? '', 300), 'style' => $style,
            'selections' => ['callouts' => $callouts, 'narration' => $narration, 'voice' => $voice, 'style' => $style, 'choices' => collect($decisions)->mapWithKeys(fn ($d) => [$d['id'] => $d['options'][0]['id']])->all(), 'kept' => $kept]];
        // A text/colour-only request becomes a free edit, validated against the real fields.
        $free = [];
        if (is_array($raw['free_edit'] ?? null) && ! empty($ctx['current_variables'])) {
            $decls = array_map(fn ($v) => ['id' => $v['id'], 'type' => $v['type'], 'default' => $v['current']], $ctx['current_variables']);
            try { $free = CompositionVariables::validate($decls, $raw['free_edit']); } catch (\Symfony\Component\HttpKernel\Exception\HttpException) { $free = []; }
        }
        $plan['free_edit'] = $free ?: null;
        $plan['new_wording'] = self::newWording([...$callouts, ...$narration], $ctx);
        $plan['credits'] = $this->credits($plan);
        return $plan;
    }

    /** Media the plan would add on top of building the composition. */
    private function credits(array $plan): array
    {
        $media = array_sum(array_column($plan['media'], 'credits'));
        $choices = collect($plan['decisions'])->sum(fn ($d) => collect($d['options'])->firstWhere('id', $plan['selections']['choices'][$d['id']] ?? null)['credits'] ?? 0);
        return ['media' => $media + $choices];
    }
}

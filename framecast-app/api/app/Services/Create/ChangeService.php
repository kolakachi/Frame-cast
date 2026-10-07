<?php

namespace App\Services\Create;

use App\Models\Asset;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Change…" on a finished video (S9, create-ui/change-from-video.html). The drawer lists the parts the video is made
 * of with what each costs to remake; "Change this moment" sends a frame and a note; "Suggest a change" drafts a note
 * from the frame. Everything the user sets is sent as one change request and planned like any other change, so the
 * plan card prices only what changes and unchanged media is reused by content.
 */
class ChangeService
{
    /** Parts the drawer lists: what is visibly in the picture and can be swapped or remade. */
    public const PICTURES = ['ai_image', 'stock_image', 'animate_image', 'generated_shot', 'ugc_take', 'talking_shot', 'talking_take', 'stock_video'];
    public const SUGGEST_MIN_CREDITS = 5;
    private const NAMES = ['ai_image' => 'Picture', 'stock_image' => 'Stock picture', 'animate_image' => 'Moving picture', 'generated_shot' => 'Shot',
        'ugc_take' => 'Talking clip', 'talking_shot' => 'Talking clip', 'talking_take' => 'Talking clip', 'stock_video' => 'Stock clip'];

    public function parts(User $user, string $conversationId, string $revisionId): array
    {
        [$c, $rev, $input] = $this->revision($user, $conversationId, $revisionId);
        $plan = (array) ($input['plan'] ?? []);
        $scenes = collect($plan['scenes'] ?? []);
        $at = fn (?string $beat) => ($s = $scenes->firstWhere('label', $beat)) ? [(float) $s['start'], (float) $s['end']] : null;
        $made = DB::table('create_plan_media')->where('plan_id', $plan['plan_id'] ?? '')->get()->keyBy('item_index');
        $planned = (array) ($plan['media'] ?? []);
        $parts = [];
        foreach ((array) ($input['plan_media'] ?? []) as $k => $m) {
            $kind = (string) ($m['kind'] ?? '');
            if (! in_array($kind, self::PICTURES, true)) continue;
            $index = (int) ($m['plan_item_index'] ?? $k);
            $row = $made->get($index);
            $price = max((int) ($planned[$index]['credits'] ?? 0), (int) ($m['credits'] ?? 0), (int) ($row->charged_credits ?? 0));
            $parts[] = ['id' => 'media-'.$index, 'kind' => $kind, 'name' => self::name($m, self::NAMES[$kind] ?? 'Part'), 'what' => Str::limit((string) ($m['description'] ?? ''), 140),
                'times' => $at($m['beat'] ?? null), 'remake_credits' => $price ?: null, 'clip' => ! in_array($kind, ['ai_image', 'stock_image'], true)];
        }
        // The user's own files in the video: a logo, a photo, their footage. Swapping them is free.
        foreach ((array) ($input['input_files'] ?? []) as $f) {
            if (($f['purpose'] ?? '') !== 'source' || ! in_array($f['asset_type'] ?? '', ['image', 'video'], true) || ! empty($f['operation'])) continue;
            $notes = json_decode((string) DB::table('create_attachments')->where('conversation_id', $c->id)->where('asset_id', $f['asset_id'] ?? 0)->value('notes_json'), true) ?: [];
            $logo = ($notes['kind'] ?? '') === 'logo' || str_contains(mb_strtolower((string) ($f['name'] ?? '')), 'logo');
            $parts[] = ['id' => 'file-'.($f['asset_id'] ?? 0), 'kind' => $logo ? 'logo' : 'your_'.$f['asset_type'], 'name' => $logo ? 'Logo' : Str::limit((string) ($f['name'] ?? 'Your file'), 40),
                'what' => (string) ($notes['use'] ?? ''), 'times' => null, 'remake_credits' => null, 'clip' => $f['asset_type'] === 'video', 'asset_id' => (int) ($f['asset_id'] ?? 0)];
        }
        $sound = collect($input['plan_media'] ?? [])->whereIn('kind', ['music', 'voiceover', 'cloned_voiceover'])->pluck('kind')->unique()->values()->all();
        $settings = (array) ($input['settings'] ?? []);
        $rebuild = CostEstimate::agent(CostEstimate::effort($settings), (int) ($settings['duration_seconds'] ?? 15), array_filter(['type' => $plan['video_type'] ?? null, 'task' => 'edit']));
        return [
            'revision' => ['id' => $rev->id, 'number' => (int) $rev->number],
            'parts' => $parts,
            'words' => array_values(array_map('strval', (array) ($plan['narration'] ?? []))),
            'sound' => ['music' => in_array('music', $sound, true), 'voice' => (string) ($plan['voice'] ?? ''), 'voiceover' => (bool) array_intersect(['voiceover', 'cloned_voiceover'], $sound),
                'music_credits' => CapabilityCatalogue::musicCredits((int) ($settings['duration_seconds'] ?? 15))],
            'estimate' => ['planning' => $this->typicalPlanning((int) $c->workspace_id), 'rebuild' => [$rebuild, 2 * $rebuild]],
        ];
    }

    /** Drafts a change for one moment from its frame. Billed like planning (half the call's cost). */
    public function suggest(User $user, string $conversationId, string $revisionId, float $time, UploadedFile $frame): array
    {
        $conversations = app(ConversationService::class);
        [$c, , $input] = $this->revision($user, $conversationId, $revisionId);
        $available = (int) $conversations->creditAvailability($user)['available'];
        abort_if($available < self::SUGGEST_MIN_CREDITS, 402, 'Top up to get a suggestion: it takes a credit or two and you have '.max(0, $available).' available.');
        abort_if((string) config('services.anthropic.key') === '' || config('create.mode') === 'fixture', 503, 'Suggestions are not available here.');
        $bytes = (string) file_get_contents($frame->getRealPath());
        abort_unless(in_array($frame->getMimeType(), ['image/jpeg', 'image/png'], true) && strlen($bytes) <= 3_000_000, 422, 'The frame must be a JPEG or PNG under 3 MB.');
        $plan = (array) ($input['plan'] ?? []);
        $scene = collect($plan['scenes'] ?? [])->first(fn ($s) => $time >= (float) ($s['start'] ?? 0) && $time < (float) ($s['end'] ?? 0));
        $ask = "This is one frame, at {$time} s, of a finished short video the user may want to change. The beat it is in, and the video's plan, are below.\n"
            ."Suggest the single change most likely to make this moment better (clearer, more readable, better framed, stronger), written as the user would ask for it: one sentence, under 20 words, specific to what you see. Then three short alternative ideas (under 5 words each).\n"
            .'Reply with JSON only: {"suggestion": string, "ideas": [string, string, string]}'
            ."\n\n".json_encode(['beat' => $scene ? array_intersect_key($scene, array_flip(['label', 'idea', 'reads'])) : null, 'narration' => $plan['narration'] ?? [], 'summary' => $plan['summary'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $model = AttachmentRoles::MODEL;
        $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', ['model' => $model, 'max_tokens' => 300, 'messages' => [['role' => 'user', 'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $frame->getMimeType(), 'data' => base64_encode($bytes)]],
                ['type' => 'text', 'text' => $ask]]]]]);
        if (! $r->successful()) {
            \App\Services\Vendors\VendorAlerts::observe('anthropic', $r->body(), $r->status());
            abort(503, 'Could not look at this moment just now. Describe the change yourself, or try again in a moment.');
        }
        $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $start = strpos($raw, '{');
        $json = $start === false ? null : json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true);
        $suggestion = trim((string) ($json['suggestion'] ?? ''));
        abort_if($suggestion === '', 502, 'Could not look at this moment just now. Describe the change yourself, or try again in a moment.');

        $key = 'suggest:'.$c->id.':'.Str::uuid();
        PlanningCosts::begin($key);
        PlanningCosts::call('suggest', $model, (array) $r->json('usage', []));
        $credits = CostEstimate::planningCharge((int) ceil(array_sum(PlanningCosts::take($key)) / 4000));
        PlanningCosts::end();
        $charged = 0;
        if ($credits > 0 && ($take = min($credits, $available)) > 0 && app(CreditService::class)->deduct((int) $user->workspace_id, $take, 'create_suggest', ['conversation_id' => $c->id, 'revision_id' => $revisionId])) $charged = $take;
        return ['suggestion' => Str::limit($suggestion, 200, ''), 'ideas' => array_values(array_slice(array_filter(array_map(fn ($i) => Str::limit(trim((string) $i), 40, ''), (array) ($json['ideas'] ?? []))), 0, 3)), 'charged' => $charged];
    }

    /**
     * The drawer's edits as one change request: frames become pictures of the current video at their time, swapped
     * files become source files saying what they replace, and the message names every part by what it is. Planning
     * then starts as for any change.
     *
     * @param array{expected_version:int, moments?:array, parts?:array, words?:array, music?:string, music_asset_id?:int, voice?:string, note?:string} $input
     * @param array<int, UploadedFile> $frames moment index => frame
     */
    public function change(User $user, string $conversationId, string $revisionId, array $input, array $frames): array
    {
        $conversations = app(ConversationService::class);
        $conversations->authorize($user, true);
        [$c, $rev, $run] = $this->revision($user, $conversationId, $revisionId);
        $c = $conversations->conversation($user, $conversationId);
        abort_unless((int) $c->version === (int) $input['expected_version'], 409, 'Conversation changed. Refresh before sending.');
        app(PlanService::class)->assertCanPayPlanning($user);
        $parts = collect($this->parts($user, $conversationId, $revisionId)['parts'])->keyBy('id');
        $mmss = fn ($s) => sprintf('%d:%02d', intdiv((int) round($s), 60), (int) round($s) % 60);
        $lines = [];
        $uploads = app(AttachmentUploadService::class);

        foreach (array_values((array) ($input['moments'] ?? [])) as $i => $m) {
            $text = trim((string) ($m['text'] ?? ''));
            if ($text === '') continue;
            $time = max(0, (float) ($m['time'] ?? 0));
            if ($frame = $frames[$i] ?? null) {
                $asset = $uploads->upload($user, $c->id, $frame, 'source', 'change-frame:'.$rev->id.':'.$i.':'.hash('sha256', (string) file_get_contents($frame->getRealPath())), (int) DB::table('create_conversations')->where('id', $c->id)->value('version'));
                DB::table('create_attachments')->where('conversation_id', $c->id)->where('asset_id', $asset->id)->update(['purpose' => 'current',
                    'notes_json' => json_encode(['kind' => 'screenshot', 'use' => Str::limit($text, 120, ''), 'time' => round($time, 1)]), 'updated_at' => now()]);
            }
            $lines[] = '- At '.$mmss($time).($frame ? ' (the frame is attached)' : '').': '.$text;
        }
        foreach ((array) ($input['parts'] ?? []) as $p) {
            $part = $parts->get((string) ($p['id'] ?? ''));
            if (! $part) continue;
            $where = $part['times'] ? ' ('.$mmss($part['times'][0]).'–'.$mmss($part['times'][1]).')' : '';
            $label = mb_strtolower($part['name']).$where;
            $text = trim((string) ($p['text'] ?? ''));
            if (($p['action'] ?? '') === 'file' && ($assetId = (int) ($p['asset_id'] ?? 0))) {
                $asset = Asset::where('workspace_id', $user->workspace_id)->whereKey($assetId)->firstOrFail();
                abort_unless(DB::table('create_attachments')->where('conversation_id', $c->id)->where('asset_id', $assetId)->exists(), 422, 'Upload the new file to this creation first.');
                DB::table('create_attachments')->where('conversation_id', $c->id)->where('asset_id', $assetId)->update(['purpose' => 'source',
                    'notes_json' => json_encode(['kind' => $part['kind'] === 'logo' ? 'logo' : ($asset->asset_type === 'video' ? 'footage' : 'photo'), 'use' => 'Replaces the '.$label]), 'updated_at' => now()]);
                $lines[] = '- Replace the '.$label.' with my file "'.$asset->title.'"'.($text !== '' ? ': '.$text : '').'.';
            } elseif (($p['action'] ?? '') === 'remake') {
                $lines[] = '- Make a new '.$label.($text !== '' ? ': '.$text : '').'.';
            } elseif ($text !== '') {
                $lines[] = '- The '.$label.': '.$text;
            }
        }
        $words = (array) ($input['words'] ?? []);
        $old = array_values(array_map('strval', (array) ($run['plan']['narration'] ?? [])));
        foreach ($words as $w) {
            $n = (int) ($w['index'] ?? -1); $text = trim((string) ($w['text'] ?? ''));
            if ($n < 0 || $text === '' || ($old[$n] ?? null) === $text) continue;
            $lines[] = '- Change the line "'.Str::limit((string) ($old[$n] ?? ''), 80).'" to: "'.$text.'"';
        }
        $music = (string) ($input['music'] ?? 'keep');
        if ($music === 'new') $lines[] = '- Music: a new track.';
        if ($music === 'none') $lines[] = '- Music: none.';
        if ($music === 'mine' && ($musicId = (int) ($input['music_asset_id'] ?? 0))) {
            abort_unless(DB::table('create_attachments')->where('conversation_id', $c->id)->where('asset_id', $musicId)->exists(), 422, 'Upload your music to this creation first.');
            DB::table('create_attachments')->where('conversation_id', $c->id)->where('asset_id', $musicId)->update(['purpose' => 'source', 'notes_json' => json_encode(['kind' => 'music', 'use' => 'The music bed']), 'updated_at' => now()]);
            $lines[] = '- Music: use my track "'.Asset::whereKey($musicId)->value('title').'".';
        }
        if (($input['voice'] ?? 'keep') === 'another') $lines[] = '- Voice: a different voice.';
        if (($note = trim((string) ($input['note'] ?? ''))) !== '') $lines[] = '- Also: '.$note;
        abort_unless($lines, 422, 'Tell me what to change first.');

        $content = 'Change version '.$rev->number." of the video:\n".implode("\n", $lines)."\nKeep everything else as it is.";
        $key = 'change:'.$rev->id.':'.hash('sha256', $content);
        $version = (int) DB::table('create_conversations')->where('id', $c->id)->value('version');
        $message = $conversations->message($user, $c->id, ['content' => $content, 'idempotency_key' => $key, 'expected_version' => $version]);
        $job = app(PlanningJobService::class)->submit($user, $c->id, (int) DB::table('create_conversations')->where('id', $c->id)->value('version'), $key.':plan', false);
        return ['message' => $message, 'planning' => $job];
    }

    /** A short name for a part from its beat or description ("Shot · Bottle", "Talking clip"). */
    private static function name(array $m, string $kind): string
    {
        if (! empty($m['beat'])) return $kind.' · '.Str::limit((string) $m['beat'], 30, '');
        $words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', (string) ($m['description'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY);
        return $words ? Str::ucfirst(implode(' ', array_slice($words, 0, 4))) : $kind;
    }

    /** The workspace's usual planning charge (median of its last 20 plans), so the drawer's total is honest. */
    private function typicalPlanning(int $workspaceId): int
    {
        $charges = DB::table('create_plans')->join('create_conversations', 'create_conversations.id', '=', 'create_plans.conversation_id')
            ->where('create_conversations.workspace_id', $workspaceId)->orderByDesc('create_plans.created_at')->limit(20)->pluck('create_plans.plan_json')
            ->map(fn ($j) => (int) (json_decode((string) $j, true)['planning_charge']['charged'] ?? 0))->filter()->sort()->values();
        return $charges->isEmpty() ? 30 : (int) $charges[intdiv($charges->count(), 2)];
    }

    /** @return array{0: object, 1: object, 2: array} the conversation, the revision and its run's input */
    private function revision(User $user, string $conversationId, string $revisionId): array
    {
        $conversations = app(ConversationService::class);
        $conversations->authorize($user);
        $c = $conversations->conversation($user, $conversationId);
        $rev = DB::table('composition_revisions')->where('conversation_id', $c->id)->where('id', $revisionId)->firstOrFail();
        $input = json_decode((string) DB::table('composition_runs')->where('id', $rev->run_id)->value('input_json'), true) ?: [];
        return [$c, $rev, $input];
    }
}

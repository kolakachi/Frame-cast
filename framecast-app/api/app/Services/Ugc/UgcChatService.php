<?php

namespace App\Services\Ugc;

use App\Http\Controllers\Api\V1\Ugc\UgcController;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * UGC Ads as a conversation (owner, 2026-10-10). Everything comes from one prompt box: product photos, footage, a link,
 * a sample UGC, a script or an idea, presenters with their voices, and an optional UGC type. A director decides each turn
 * whether to ask one question (only what it cannot infer) or to pitch one video with its price. "Make it" hands the pitch
 * to the existing UGC pipeline, which makes each video as a Classic project.
 *
 * The existing UGC endpoints do the work (read-link, reference, plan, generate-one-shot, generate, takes), called in
 * process with the user's own request so their checks, gates and pricing stay the single source of truth.
 */
final class UgcChatService
{
    public const TYPES = ['testimonial', 'problem_solution', 'green_screen', 'before_after', 'unboxing', 'comparison', 'pov_hook',
        'asmr', 'day_in_life', 'skit', 'listicle', 'founder_story', 'reaction'];
    private const PACE = ['slower' => 0.9, 'natural' => 1.0, 'faster' => 1.12];
    private const DELIVERY = ['friendly' => 'Warm and friendly, like telling a friend about something that worked',
        'excited' => 'Excited and upbeat, genuinely impressed', 'calm' => 'Calm and assured, like an expert explaining',
        'deadpan' => 'Dry and deadpan, understated'];

    public function __construct(private AIGenerationAdapter $ai, private CreditService $credits) {}

    public function create(User $user): string
    {
        $id = (string) Str::uuid();
        DB::table('ugc_chats')->insert(['id' => $id, 'workspace_id' => $user->workspace_id, 'user_id' => $user->getKey(),
            'state_json' => json_encode(['attachments' => [], 'presenters' => [], 'type' => 'auto', 'aspect' => '9:16']), 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public function chat(User $user, string $id): object
    {
        return DB::table('ugc_chats')->where('id', $id)->where('workspace_id', $user->workspace_id)->first() ?? abort(404, 'Chat not found.');
    }

    /**
     * The user's turn: update what the chat knows from the prompt box, then let the director ask or pitch.
     * $input: text, attachments [{asset_id, role: product|footage|sample}], link, presenters [{character_id|null, voice{pace, delivery, direction}}],
     * type (auto|a TYPE), aspect, length.
     */
    public function message(Request $request, string $id, array $input): array
    {
        $user = $request->user();
        $chat = $this->chat($user, $id);
        $state = json_decode((string) $chat->state_json, true) ?: [];
        $before = $state;

        foreach ($input['attachments'] ?? [] as $a) {
            $asset = DB::table('assets')->where('id', (int) $a['asset_id'])->where('workspace_id', $user->workspace_id)->first(['id', 'asset_type', 'title']);
            if (! $asset) continue;
            $role = in_array($a['role'] ?? null, ['product', 'footage', 'sample'], true) ? $a['role'] : ($asset->asset_type === 'image' ? 'product' : 'footage');
            $state['attachments'] = array_values(array_filter($state['attachments'] ?? [], fn ($x) => (int) $x['asset_id'] !== (int) $asset->id));
            $state['attachments'][] = ['asset_id' => (int) $asset->id, 'role' => $role, 'type' => $asset->asset_type, 'title' => (string) $asset->title];
            // A sample UGC: its structure (hook, beats, pacing), read once. Never its words, face or music.
            if ($role === 'sample' && ($state['sample']['asset_id'] ?? null) !== (int) $asset->id) {
                $ref = $this->call($request, fn (UgcController $c, Request $r) => app()->call([$c, 'reference'], ['request' => $r]), ['asset_id' => (int) $asset->id]);
                $state['sample'] = ['asset_id' => (int) $asset->id, 'reference' => $ref['reference'] ?? null];
            }
        }
        if (! empty($input['link']) && ($state['link']['url'] ?? null) !== $input['link']) {
            $facts = $this->call($request, fn (UgcController $c, Request $r) => app()->call([$c, 'readLink'], ['request' => $r]), ['url' => (string) $input['link']]);
            $state['link'] = ['url' => (string) $input['link'], 'facts' => isset($facts['error']) ? null : $facts];
        }
        foreach (['presenters', 'type', 'aspect', 'length'] as $k) if (array_key_exists($k, $input)) $state[$k] = $input[$k];

        DB::table('ugc_chat_messages')->insert(['chat_id' => $id, 'role' => 'user', 'kind' => 'text', 'content' => mb_substr((string) ($input['text'] ?? ''), 0, 4000),
            'payload_json' => json_encode(['attachments' => $input['attachments'] ?? [], 'link' => $input['link'] ?? null]), 'created_at' => now()]);
        DB::table('ugc_chats')->where('id', $id)->update(['state_json' => json_encode($state), 'updated_at' => now()]);
        // What we read from their page and sample is shown back before anything is decided on it.
        $facts = [];
        if (! empty($input['link']) && ($before['link']['url'] ?? null) !== ($state['link']['url'] ?? null)) {
            $facts[] = ['source' => 'page', 'text' => ! empty($state['link']['facts'])
                ? Str::limit(trim(implode(' · ', array_filter([$state['link']['facts']['product'] ?? '', $state['link']['facts']['context'] ?? '']))), 240)
                : 'I could not read that page. Tell me about the product instead.'];
        }
        if (! empty($state['sample']['reference']) && ($before['sample']['asset_id'] ?? null) !== ($state['sample']['asset_id'] ?? null)) {
            $facts[] = ['source' => 'sample', 'text' => self::sampleLine($state['sample']['reference'])];
        }
        if ($facts) $this->say($id, 'facts', '', ['facts' => $facts]);

        return $this->turn($request, $id, $state);
    }

    /** The director's turn: one question, or one pitch with its price. */
    private function turn(Request $request, string $id, array $state): array
    {
        $decision = null; $error = '';
        for ($try = 0; $try < 3 && ! $decision; $try++) {
            $out = $this->ai->generate('ugc_chat_turn', [
                'transcript' => $this->transcript($id), 'materials' => $this->materials($state),
                'presenters' => $this->presentersText($state), 'type' => $state['type'] ?? 'auto',
                'shape' => ($state['aspect'] ?? '9:16').(! empty($state['length']) ? ', '.$state['length'].' seconds' : ', length open'),
                'previous_error' => $error,
            ], 1500, 0.4);
            try { $d = UgcPlan::decodeModelJson((string) ($out['content'] ?? '')); } catch (\Throwable) { $d = []; }
            if (($d['action'] ?? null) === 'ask' && trim((string) ($d['question'] ?? '')) !== '') $decision = $d;
            elseif (($d['action'] ?? null) === 'pitch' && trim((string) ($d['summary'] ?? '')) !== '') {
                // Its own words must fit the length; a script the user wrote is kept whatever its length (it wins).
                $budget = (int) floor(2.6 * max(5, min(60, (int) ($state['length'] ?? $d['duration_seconds'] ?? 15))));
                $words = str_word_count((string) ($d['script'] ?? ''));
                if ($try < 2 && $words > $budget && ! $this->userWrote($id, (string) $d['script'])) {
                    $error = "Your script is {$words} words; at this length it can be at most {$budget}. Write it again, shorter, keeping the hook.";
                    continue;
                }
                $decision = $d;
            }
            else $error = 'Your last answer was not valid JSON with action "ask" or "pitch". Answer again with JSON only.';
        }
        if (! $decision) return $this->say($id, 'error', 'I could not work that out. Could you say it another way?');
        if ($decision['action'] === 'ask') {
            $chips = array_slice(array_values(array_filter(array_map(fn ($c) => mb_substr(trim((string) $c), 0, 60), (array) ($decision['chips'] ?? [])))), 0, 4);
            return $this->say($id, 'question', mb_substr((string) $decision['question'], 0, 500), ['chips' => $chips]);
        }
        return $this->pitch($request, $id, $state, $decision);
    }

    /** Turn the director's pitch into the existing UGC plan, one take per presenter, each priced by the pipeline's own rules. */
    private function pitch(Request $request, string $id, array $state, array $d): array
    {
        $type = in_array($d['type'] ?? null, self::TYPES, true) ? $d['type'] : 'testimonial';
        if (in_array($state['type'] ?? 'auto', self::TYPES, true)) $type = $state['type'];
        $format = in_array($d['format'] ?? null, UgcPlan::FORMATS, true) ? $d['format'] : 'auto';
        $seconds = (int) max(5, min(60, (int) ($state['length'] ?? $d['duration_seconds'] ?? 15)));
        $footage = array_values(array_map(fn ($a) => $a['asset_id'], array_filter($state['attachments'] ?? [], fn ($a) => $a['role'] === 'footage')));
        $plan = $this->call($request, fn (UgcController $c, Request $r) => app()->call([$c, 'plan'], ['request' => $r]), array_filter([
            'script' => mb_substr((string) ($d['script'] ?? ''), 0, 1500), 'context' => mb_substr((string) ($d['context'] ?? $d['summary']), 0, 1500),
            'product' => mb_substr((string) ($d['product'] ?? ($state['link']['facts']['product'] ?? '')), 0, 200), 'format' => $format,
            'duration_seconds' => $seconds, 'language' => 'en', 'footage_asset_ids' => $footage ?: null,
            'reference' => ! empty($state['sample']['reference']) ? array_intersect_key($state['sample']['reference'], ['duration' => 1, 'shape' => 1, 'beats' => 1]) : null,
        ], fn ($v) => $v !== null && $v !== ''));
        if (isset($plan['error'])) return $this->say($id, 'error', $plan['error']['message'] ?? 'The plan could not be made. Try again.');

        $products = array_values(array_map(fn ($a) => $a['asset_id'], array_filter($state['attachments'] ?? [], fn ($a) => $a['role'] === 'product')));
        $presenters = $state['presenters'] ?? [];
        if (! $presenters) $presenters = [['character_id' => $plan['presenter_character_id'] ?? null, 'voice' => null, 'picked' => true]];
        $takes = [];
        $title = mb_substr(trim((string) ($d['title'] ?? 'UGC video')), 0, 120);
        foreach ($presenters as $p) $takes[] = $this->take($request, $plan, $p, $products, (string) ($d['product'] ?? ''), $type, $title);

        // The director writes before anyone is cast: a single presenter it could not name is named now.
        if (count($takes) === 1 && $takes[0]['character_id']) {
            $d['summary'] = preg_replace('/^(A|The) (presenter|creator|customer|woman|man|person)\b/i', $takes[0]['name'], (string) $d['summary'], 1);
        }
        $total = array_sum(array_column($takes, 'credits'));
        if (! DB::table('ugc_chats')->where('id', $id)->value('title')) DB::table('ugc_chats')->where('id', $id)->update(['title' => $title]);
        return $this->say($id, 'pitch', mb_substr((string) $d['summary'], 0, 600), [
            'type' => $type, 'title' => $title, 'plan' => ['format' => $plan['format'], 'script' => $plan['script'], 'segments' => $plan['segments']],
            'takes' => $takes, 'credits' => $total, 'aspect' => $state['aspect'] ?? '9:16', 'product_asset_ids' => $products, 'made' => false,
        ]);
    }

    /** One presenter's video: their voice applied to every spoken shot; one take when the pipeline can, composed otherwise. */
    private function take(Request $request, array $plan, array $p, array $products, string $product, string $type, string $title): array
    {
        $voice = $p['voice'] ?? null;
        $segments = array_map(function ($s) use ($voice) {
            if ($voice && trim((string) ($s['script_text'] ?? '')) !== '') {
                $s['speed'] = self::PACE[$voice['pace'] ?? 'natural'] ?? 1.0;
                $s['voice_direction'] = trim((self::DELIVERY[$voice['delivery'] ?? 'friendly'] ?? self::DELIVERY['friendly']).'. '.(string) ($voice['direction'] ?? ''), ' .').'.';
            }
            return $s;
        }, $plan['segments']);
        $characterId = $p['character_id'] ?? null;
        $name = $characterId ? (string) DB::table('characters')->where('id', $characterId)->value('name') : 'A fitting presenter';
        $oneShot = self::oneShotEligible($plan['format'], $segments);
        if ($oneShot) {
            $v = array_filter(['format' => $plan['format'], 'segments' => $segments, 'script' => $plan['script'], 'character_id' => $characterId,
                'engine' => $characterId ? 'omni' : 'seedance25', 'quality' => 'full', 'product_asset_id' => $products[0] ?? null,
                'product_asset_ids' => $products ?: null, 'product' => mb_substr($product, 0, 200), 'language' => 'en',
                'presenter_description' => $characterId ? null : mb_substr((string) ($plan['presenter'] ?? ''), 0, 400), 'title' => $title], fn ($x) => $x !== null && $x !== '');
            $price = UgcOneShotPricing::price($request->user(), $v, $segments, $this->credits);
            $v['credits'] = (int) $price['quote'];
            return ['character_id' => $characterId, 'name' => $name, 'picked' => ! empty($p['picked']), 'voice' => $voice, 'mode' => 'one_shot',
                'engine' => $price['engine'], 'credits' => (int) $price['quote'], 'request' => $v];
        }
        $per = UgcPlan::quote($segments);
        $v = array_filter(['format' => $plan['format'], 'segments' => $segments, 'script' => $plan['script'], 'character_ids' => $characterId ? [$characterId] : null,
            'aspect_ratio' => '9:16', 'language' => 'en', 'product_asset_id' => $products[0] ?? null, 'credits_per_character' => $per,
            'title' => $title], fn ($x) => $x !== null && $x !== '');
        return ['character_id' => $characterId, 'name' => $name, 'picked' => ! empty($p['picked']), 'voice' => $voice, 'mode' => 'composed',
            'engine' => 'composed', 'credits' => $per, 'request' => $v];
    }

    /** The pipeline's own rule for one fluid take (web/src/services/ugcGenerationMode.js). */
    public static function oneShotEligible(string $format, array $segments): bool
    {
        if ($format === 'text_led') return false;
        if (array_sum(array_map(fn ($s) => (float) ($s['seconds'] ?? 0), $segments)) > 30) return false;
        foreach ($segments as $s) if (($s['kind'] ?? '') === 'b_roll' && in_array($s['source'] ?? '', ['upload', 'stock'], true)) return false;
        foreach ($segments as $s) if (trim((string) ($s['script_text'] ?? '')) !== '') return true;
        return false;
    }

    /** "Make it": the pitch's takes go to the pipeline, which checks the price again and spends the credits. */
    public function make(Request $request, string $id, int $messageId, string $requestId): array
    {
        $chat = $this->chat($request->user(), $id);
        // One "Make it" at a time per pitch: a double click never starts it twice.
        $lock = \Illuminate\Support\Facades\Cache::lock('ugc-chat-make:'.$messageId, 120);
        if (! $lock->get()) abort(409, 'This video is already being made.');
        try {
            return $this->makeLocked($request, $chat, $id, $messageId, $requestId);
        } finally {
            $lock->release();
        }
    }

    private function makeLocked(Request $request, object $chat, string $id, int $messageId, string $requestId): array
    {
        $msg = DB::table('ugc_chat_messages')->where('chat_id', $chat->id)->where('id', $messageId)->where('kind', 'pitch')->first() ?? abort(404, 'Pitch not found.');
        $pitch = json_decode((string) $msg->payload_json, true);
        if (! empty($pitch['made'])) abort(409, 'This video is already being made.');
        $runs = []; $failed = null;
        foreach ($pitch['takes'] as $i => $t) {
            $payload = $t['request'] + ['consent' => true, 'reviewed' => true, 'request_id' => \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'wyv-ugc-chat:'.$requestId.':'.$i)->toString()];
            $out = $this->call($request, fn (UgcController $c, Request $r) => $t['mode'] === 'one_shot'
                ? app()->call([$c, 'generateOneShot'], ['request' => $r]) : app()->call([$c, 'generate'], ['request' => $r]), $payload);
            if (isset($out['error'])) { $failed = $out['error']['message'] ?? 'One video could not be started.'; break; }
            $runs[] = ['run_id' => $out['run_id'] ?? null, 'take_ids' => array_column($out['takes'] ?? [], 'id'), 'name' => $t['name'], 'credits' => (int) ($out['credits_quoted'] ?? $t['credits'])];
        }
        if ($runs) {
            $pitch['made'] = true;
            DB::table('ugc_chat_messages')->where('id', $messageId)->update(['payload_json' => json_encode($pitch)]);
        }
        if (! $runs) return $this->say($id, 'error', $failed ?? 'The video could not be started.');
        return $this->say($id, 'making', $failed ? 'Started '.count($runs).' of '.count($pitch['takes']).'. '.$failed : 'Making it now.', ['pitch_id' => $messageId, 'runs' => $runs]);
    }

    /** The chat with its messages; a "making" message carries each video's live status from the pipeline. */
    public function show(Request $request, string $id): array
    {
        $chat = $this->chat($request->user(), $id);
        // What the user sent is shown as the same pills they attached: each file's name, kind and a preview.
        $rows = DB::table('ugc_chat_messages')->where('chat_id', $id)->orderBy('id')->get();
        $ids = $rows->where('role', 'user')->flatMap(fn ($m) => array_column(json_decode((string) $m->payload_json, true)['attachments'] ?? [], 'asset_id'))->unique()->all();
        $assets = $ids ? DB::table('assets')->where('workspace_id', $chat->workspace_id)->whereIn('id', $ids)->get(['id', 'title', 'asset_type'])->keyBy('id') : collect();
        $messages = $rows->map(function ($m) use ($request, $assets) {
            $payload = json_decode((string) $m->payload_json, true) ?: [];
            if ($m->role === 'user') foreach ($payload['attachments'] ?? [] as $i => $a) {
                $asset = $assets->get((int) $a['asset_id']);
                if (! $asset) continue;
                $payload['attachments'][$i] += ['title' => (string) $asset->title, 'type' => $asset->asset_type,
                    'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute('media.assets.content', now()->addHours(2), ['assetId' => $asset->id])];
            }
            if ($m->kind === 'pitch') foreach ($payload['takes'] ?? [] as $i => $t) unset($payload['takes'][$i]['request']);
            if ($m->kind === 'making') {
                foreach ($payload['runs'] ?? [] as $i => $run) {
                    $takes = $run['run_id'] ? $this->call($request, fn (UgcController $c, Request $r) => app()->call([$c, 'takes'], ['request' => $r]), ['run' => $run['run_id']], 'GET') : [];
                    $payload['runs'][$i]['takes'] = array_map(fn ($t) => array_intersect_key($t, array_flip(['id', 'status', 'thumbnail_url', 'thumbnail_type', 'credits', 'format', 'revision_export_id'])), $takes['takes'] ?? []);
                }
            }
            return ['id' => (int) $m->id, 'role' => $m->role, 'kind' => $m->kind, 'content' => $m->content, 'payload' => $payload, 'at' => $m->created_at];
        })->all();
        return ['id' => $chat->id, 'title' => $chat->title, 'state' => json_decode((string) $chat->state_json, true), 'messages' => $messages];
    }

    /** A sample's structure in one line: its shape, length and beats, never its words. */
    private static function sampleLine(array $ref): string
    {
        $parts = array_filter([
            is_string($ref['shape'] ?? null) ? $ref['shape'] : null,
            ! empty($ref['duration']) ? round((float) $ref['duration']).' s' : null,
            is_array($ref['beats'] ?? null) ? count($ref['beats']).' beats' : null,
        ]);
        return ($parts ? implode(' · ', $parts).'. ' : '').'We copy how it is built, never its words, face or music.';
    }

    /** "Rewrite with AI" on a pasted script: sharper, same length and meaning, nothing invented. Free. */
    public function rewrite(Request $request, string $id, string $script): string
    {
        $chat = $this->chat($request->user(), $id);
        $state = json_decode((string) $chat->state_json, true) ?: [];
        $out = $this->ai->generate('ugc_script_rewrite', ['script' => $script, 'materials' => $this->materials($state)], 900, 0.6, ['operation' => 'ugc_script_rewrite']);
        try { $d = UgcPlan::decodeModelJson((string) ($out['content'] ?? '')); } catch (\Throwable) { $d = []; }
        $new = trim((string) ($d['script'] ?? ''));
        if ($new === '') abort(422, 'Could not rewrite it just now. Try again.');
        return mb_substr($new, 0, 4000);
    }

    private function say(string $id, string $kind, string $content, array $payload = []): array
    {
        $mid = DB::table('ugc_chat_messages')->insertGetId(['chat_id' => $id, 'role' => 'assistant', 'kind' => $kind, 'content' => $content,
            'payload_json' => json_encode($payload), 'created_at' => now()]);
        DB::table('ugc_chats')->where('id', $id)->update(['updated_at' => now()]);
        if ($kind === 'pitch') foreach ($payload['takes'] ?? [] as $i => $t) unset($payload['takes'][$i]['request']);
        return ['id' => $mid, 'role' => 'assistant', 'kind' => $kind, 'content' => $content, 'payload' => $payload];
    }

    /** Whether the script came from the user's own messages (pasted), which keeps it word for word. */
    private function userWrote(string $id, string $script): bool
    {
        $norm = fn (string $t) => preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($t));
        $head = trim(mb_substr($norm($script), 0, 80));
        if ($head === '') return false;
        return DB::table('ugc_chat_messages')->where('chat_id', $id)->where('role', 'user')->pluck('content')
            ->contains(fn ($c) => str_contains($norm((string) $c), $head));
    }

    private function transcript(string $id): string
    {
        return DB::table('ugc_chat_messages')->where('chat_id', $id)->orderByDesc('id')->limit(16)->get()->reverse()
            ->map(fn ($m) => ($m->role === 'user' ? 'User: ' : 'You: ').match ($m->kind) {
                'pitch' => '[pitched] '.$m->content.self::pitchLine($m), 'making' => '[making the video]', default => (string) $m->content,
            })->implode("\n");
    }

    /** What a pitch was, so "same again" or "shorter" starts from it: its type, length and words. */
    private static function pitchLine(object $m): string
    {
        $p = json_decode((string) $m->payload_json, true) ?: [];
        $seconds = (int) round(array_sum(array_map(fn ($s) => (float) ($s['seconds'] ?? 0), $p['plan']['segments'] ?? [])));
        return ' (type '.($p['type'] ?? '?').', '.$seconds.' s, script: "'.mb_substr((string) ($p['plan']['script'] ?? ''), 0, 600).'")';
    }

    private function materials(array $s): string
    {
        $lines = [];
        foreach ($s['attachments'] ?? [] as $a) $lines[] = "- {$a['role']} {$a['type']}: {$a['title']}";
        if (! empty($s['link']['facts'])) $lines[] = '- Product page '.$s['link']['url'].': '.json_encode(array_intersect_key($s['link']['facts'], array_flip(['product', 'context', 'must_include'])));
        elseif (! empty($s['link']['url'])) $lines[] = '- Product page '.$s['link']['url'].' (could not be read)';
        if (! empty($s['sample']['reference'])) $lines[] = '- Sample UGC structure (copy its shape, never its words): '.json_encode($s['sample']['reference']);
        return $lines ? implode("\n", $lines) : '(nothing yet)';
    }

    private function presentersText(array $s): string
    {
        $out = [];
        foreach ($s['presenters'] ?? [] as $p) {
            $name = ! empty($p['character_id']) ? (string) DB::table('characters')->where('id', $p['character_id'])->value('name') : 'let us pick';
            $v = $p['voice'] ?? null;
            $out[] = $name.($v ? ' (voice: '.($v['pace'] ?? 'natural').' pace, '.($v['delivery'] ?? 'friendly').(! empty($v['direction']) ? ', '.$v['direction'] : '').')' : ' (voice: their default)');
        }
        return $out ? implode('; ', $out) : 'none chosen (pick one that fits)';
    }

    /** Call an existing UGC endpoint in process as this user; its validation and refusals come back as ['error' => …]. */
    private function call(Request $outer, callable $action, array $payload, string $method = 'POST'): array
    {
        $inner = Request::create('/internal/ugc-chat', $method, $payload);
        $inner->headers->set('Accept', 'application/json');
        $inner->setUserResolver(fn () => $outer->user());
        try {
            $response = $action(app(UgcController::class), $inner);
        } catch (ValidationException $e) {
            return ['error' => ['code' => 'validation_failed', 'message' => collect($e->errors())->flatten()->first() ?? 'Invalid request.']];
        }
        $body = $response->getData(true);
        if ($response->getStatusCode() >= 400) return ['error' => $body['error'] ?? ['code' => 'ugc_refused', 'message' => 'The request was refused.']];
        return $body['data'] ?? [];
    }
}

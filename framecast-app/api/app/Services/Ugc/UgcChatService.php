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
    /** Superseded by UgcTypes::unavailable(), per user and opened by UGC_TYPES_LIVE (#8). Kept for older callers. */
    public const SOON = [];
    private const NOT_FOUNDER = 'Make it a testimonial instead';
    /** Everyday outfits that suit any presenter; each ad gets one this workspace has not used lately. */
    private const WARDROBE = ['a rust ribbed knit sweater', 'a white cotton tee under an open light-wash denim shirt', 'a soft sage green hoodie',
        'a navy crew-neck sweatshirt', 'a fitted black tee', 'a cream cable-knit cardigan over a white tee', 'a light blue button-up shirt with the sleeves rolled',
        'a navy and white striped long-sleeve top', 'a heather grey zip-up', 'an olive utility overshirt over a white tee', 'a mustard yellow knit top', 'a burgundy henley'];
    private const PACE = ['slower' => 0.9, 'natural' => 1.0, 'faster' => 1.12];
    private const DELIVERY = ['friendly' => 'Warm and friendly, like telling a friend about something that worked',
        'excited' => 'Excited and upbeat, genuinely impressed', 'calm' => 'Calm and assured, like an expert explaining',
        'deadpan' => 'Dry and deadpan, understated'];

    public function __construct(private AIGenerationAdapter $ai, private CreditService $credits, private ProductVision $vision, private ProductImageFinder $images) {}

    /** Everyone once UGC_CHAT_OPEN_TO_ALL is on; until then the team (Create's allowed addresses and domains) and super admins. */
    public static function allowed(User $user): bool
    {
        if (config('services.ugc.chat_open_to_all')) return true;
        if (in_array($user->role, ['super_admin', 'platform_admin'], true)) return true;
        $email = strtolower(trim((string) $user->email));
        return $email !== '' && (in_array($email, (array) config('services.ugc.chat_allowed_emails', []), true)
            || in_array($email, (array) config('create.allowed_emails', []), true)
            || in_array(substr(strrchr($email, '@') ?: '', 1), (array) config('create.allowed_domains', []), true));
    }

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
            $role = in_array($a['role'] ?? null, UgcTypes::ROLES, true) ? $a['role'] : ($asset->asset_type === 'image' ? 'product' : 'footage');
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
            // Its product photos come too (VISION): after the user's own, which stay first. Tried even when the text
            // could not be read: a thin page can still name good photos.
            $linked = $this->images->import($user, (string) $input['link'], isset($facts['error']) ? '' : (string) ($facts['product'] ?? ''));
            foreach ($linked as $assetId) {
                if (collect($state['attachments'] ?? [])->contains(fn ($x) => (int) $x['asset_id'] === $assetId)) continue;
                $state['attachments'][] = ['asset_id' => $assetId, 'role' => 'product', 'type' => 'image', 'title' => (string) DB::table('assets')->where('id', $assetId)->value('title'), 'from_link' => true];
            }
            $state['link']['images'] = $linked;
        }
        // What each product photo shows, read once (VISION): the director describes the product from this, never from
        // a file name.
        $unread = array_values(array_map(fn ($a) => (int) $a['asset_id'], array_filter($state['attachments'] ?? [], fn ($a) => $a['role'] === 'product' && $a['type'] === 'image' && ! isset($a['look']))));
        if ($unread) {
            $sheets = $this->vision->sheets($unread, (int) $user->workspace_id);
            foreach ($state['attachments'] as $i => $a) {
                if (! isset($sheets[(int) $a['asset_id']])) continue;
                $state['attachments'][$i]['look'] = ProductVision::line($sheets[(int) $a['asset_id']]);
                $state['attachments'][$i]['shot'] = $sheets[(int) $a['asset_id']]['kind'];
            }
        }
        // Presenters are this workspace's characters or stock actors, each with a face to anchor; anything else is
        // dropped (an id from another workspace leaked its name into the pitch).
        if (array_key_exists('presenters', $input)) {
            $ids = array_filter(array_map(fn ($p) => (int) ($p['character_id'] ?? 0), $input['presenters'] ?? []));
            $usable = $ids ? self::castable($user)->whereIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all() : [];
            $input['presenters'] = array_values(array_filter($input['presenters'] ?? [], fn ($p) => empty($p['character_id']) || in_array((int) $p['character_id'], $usable, true)));
        }
        foreach (['presenters', 'type', 'aspect', 'length'] as $k) if (array_key_exists($k, $input)) $state[$k] = $input[$k];
        if (trim((string) ($input['text'] ?? '')) === self::NOT_FOUNDER && ($state['type'] ?? '') === 'founder_story') $state['type'] = 'testimonial';

        DB::table('ugc_chat_messages')->insert(['chat_id' => $id, 'role' => 'user', 'kind' => 'text', 'content' => mb_substr((string) ($input['text'] ?? ''), 0, 4000),
            'payload_json' => json_encode(['attachments' => array_map(fn ($a) => ['asset_id' => (int) $a['asset_id'], 'role' => $a['role'] ?? null], $input['attachments'] ?? []),
                'link' => $input['link'] ?? null]), 'created_at' => now()]);
        DB::table('ugc_chats')->where('id', $id)->update(['state_json' => json_encode($state), 'updated_at' => now()]);
        // What we read from their page and sample is shown back before anything is decided on it.
        $facts = [];
        if (! empty($input['link']) && ($before['link']['url'] ?? null) !== ($state['link']['url'] ?? null)) {
            $facts[] = ['source' => 'page', 'text' => ! empty($state['link']['facts'])
                ? Str::limit(trim(implode(' · ', array_filter([$state['link']['facts']['product'] ?? '', $state['link']['facts']['context'] ?? '']))), 240)
                : 'I could not read that page. Tell me about the product instead.', 'images' => $state['link']['images'] ?? []];
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
                'presenters' => $this->presentersText($state, (int) $request->user()->workspace_id), 'type' => $state['type'] ?? 'auto',
                'shape' => ($state['aspect'] ?? '9:16').(! empty($state['length']) ? ', '.$state['length'].' seconds' : ', length open'),
                'settings' => $this->settingsText($request), 'type_contracts' => json_encode(UgcTypes::contracts($request->user())), 'previous_error' => $error,
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
        $unavailable = UgcTypes::unavailable($request->user());
        $type = in_array($d['type'] ?? null, self::TYPES, true) && ! in_array($d['type'], $unavailable, true) ? $d['type'] : 'testimonial';
        if (in_array($state['type'] ?? 'auto', self::TYPES, true) && ! in_array($state['type'], $unavailable, true)) $type = $state['type'];
        // Its new production route only where it is open to this user (#8); otherwise made the way it was before.
        $live = UgcTypes::live($type, $request->user());
        // A founder story is told by the founder (owner, 2026-10-10): an actor saying "I made this" for a real brand
        // deceives. It needs the user's own face, an uploaded photo or a saved character, on every presenter.
        if ($type === 'founder_story' && ! $this->ownFaces($request, $state)) {
            return $this->say($id, 'question', 'A founder story is told by the founder, so it needs your own face. Add your photo (Presenters → upload) or pick your saved character, then send again. Or I can make it a testimonial instead.', ['chips' => [self::NOT_FOUNDER]]);
        }
        $attachments = $state['attachments'] ?? [];
        $roleAsset = fn ($role) => collect($attachments)->firstWhere('role', $role)['asset_id'] ?? null;
        $composition = [];
        if ($live && $type === 'green_screen') {
            if (! $roleAsset('background')) return $this->say($id, 'question', 'Upload the page or screenshot to show behind the presenter, choose Background for that file, then send again.');
            $composition = ['layout' => 'green_screen', 'background_asset_id' => $roleAsset('background')];
        }
        if ($live && in_array($type, ['before_after', 'comparison'], true) && ($roleAsset('left') || $roleAsset('right'))) {
            if (! $roleAsset('left') || ! $roleAsset('right')) return $this->say($id, 'question', 'A split comparison needs both files. Assign one upload to Left / Before and the other to Right / After, then send again.');
            $composition = ['layout' => 'split', 'left_asset_id' => $roleAsset('left'), 'right_asset_id' => $roleAsset('right')];
        }
        $castIds = array_values(array_filter(array_column($state['presenters'] ?? [], 'character_id')));
        if ($type === 'skit' && (count($state['presenters'] ?? []) !== 2 || count($castIds) !== 2 || count(array_unique($castIds)) !== 2)) {
            return $this->say($id, 'question', 'Choose exactly two different presenters for this skit. They will play speaker 1 and speaker 2 in one video.');
        }
        $format = (UgcTypes::contracts($request->user())[$type] ?? null)[2] ?? UgcTypes::format($type);
        $seconds = (int) max(5, min(60, (int) ($state['length'] ?? $d['duration_seconds'] ?? 15)));
        $footage = array_values(array_map(fn ($a) => $a['asset_id'], array_filter($state['attachments'] ?? [], fn ($a) => $a['role'] === 'footage')));
        // A reaction is silent: its words are a headline, never a spoken script (the planner refuses one).
        if ($format === 'reaction' || $type === 'reaction') {
            $format = 'reaction';
            $d['context'] = trim((string) ($d['context'] ?? $d['summary']).' Headline idea: '.trim((string) ($d['script'] ?? '')));
            $d['script'] = '';
        }
        // Variety (owner, 2026-10-10: one actor in the same shirt in 11 of 12 ads): when we pick, recently cast faces
        // sit out, and every ad gets an outfit this workspace has not used lately.
        [$recentFaces, $recentOutfits, $recentSettings] = $this->recentCasting($request);
        // Where it is filmed (owner, 2026-10-11): the director's pick from the settings that have a background photo;
        // otherwise one not used lately. The outfit suits the place.
        $settings = UgcSettings::available();
        // A place the user set on every presenter wins over the director's (owner, 2026-10-11: background in the presenter settings).
        $chosen = array_values(array_unique(array_filter(array_map(fn ($p) => is_string($p['setting'] ?? null) && isset($settings[$p['setting']]) ? $p['setting'] : null, $state['presenters'] ?? []))));
        $everyone = $chosen && count($chosen) === 1 && count(array_filter($state['presenters'] ?? [], fn ($p) => empty($p['setting']))) === 0;
        $named = is_string($d['setting'] ?? null) ? $d['setting'] : '';
        $setting = $everyone ? $chosen[0] : (isset($settings[$named]) ? $named : $this->pickSetting($settings, $recentSettings));
        if ($type === 'green_screen') $setting = null;
        $wardrobe = $this->pickWardrobe($recentOutfits, $setting !== null ? ($settings[$setting]['wardrobe'] ?? []) : []);
        if ($type === 'green_screen') $wardrobe = 'a charcoal grey top';
        $products = array_values(array_map(fn ($a) => $a['asset_id'], array_filter($state['attachments'] ?? [], fn ($a) => $a['role'] === 'product')));
        $look = $products ? $this->vision->look($products, (int) $request->user()->workspace_id) : '';
        $plan = $this->call($request, fn (UgcController $c, Request $r) => app()->call([$c, 'plan'], ['request' => $r]), array_filter([
            'avoid_character_ids' => empty($state['presenters']) && $recentFaces ? $recentFaces : null,
            'script' => mb_substr((string) ($d['script'] ?? ''), 0, 1500),
            'context' => mb_substr('UGC type: '.$type.'. '.(UgcTypes::contracts($request->user())[$type][3] ?? UgcTypes::direction($type)).' '.($look !== '' ? 'The product, exactly as its photos show: '.$look.'. Every shot that shows it shows this same object, at this size. ' : '').trim((string) ($d['context'] ?? $d['summary'])).($setting !== null ? ' Filmed in '.$settings[$setting]['place'].'.' : '').' Wardrobe for this ad: '.$wardrobe.'. Describe the presenter wearing exactly this in every shot; ignore any clothing in a presenter\'s saved description.', 0, 1500),
            'product' => mb_substr((string) ($d['product'] ?? ($state['link']['facts']['product'] ?? '')), 0, 200), 'format' => $format,
            'duration_seconds' => $seconds, 'language' => 'en', 'footage_asset_ids' => $footage ?: null,
            'reference' => ! empty($state['sample']['reference']) ? array_intersect_key($state['sample']['reference'], ['duration' => 1, 'shape' => 1, 'beats' => 1]) : null,
        ], fn ($v) => $v !== null && $v !== ''));
        if (isset($plan['error'])) return $this->say($id, 'error', $plan['error']['message'] ?? 'The plan could not be made. Try again.');

        $presenters = $state['presenters'] ?? [];
        if ($live && in_array($type, ['asmr', 'pov_hook'], true)) $presenters = [['character_id' => null, 'voice' => $type === 'asmr' ? ($presenters[0]['voice'] ?? null) : null]];
        if (! $presenters) $presenters = [['character_id' => $plan['presenter_character_id'] ?? null, 'voice' => null, 'picked' => true]];
        $takes = [];
        $title = mb_substr(trim((string) ($d['title'] ?? 'UGC video')), 0, 120);
        if ($type === 'skit') {
            $speakers = UgcTypes::spokenSpeakers($plan['segments']);
            if (array_diff($speakers, [1, 2]) || count($speakers) !== 2) {
                return $this->say($id, 'error', 'The skit plan must assign each shot to speaker 1 or 2 and include both speakers. Ask for a revised plan; no credits were spent.');
            }
            $presenters = [array_merge($presenters[0], ['cast_character_ids' => $castIds, 'speaker_voices' => [1 => $presenters[0]['voice'] ?? null, 2 => $presenters[1]['voice'] ?? null]])];
        }
        foreach ($presenters as $p) {
            // A presenter with their own place gets it, and clothes that suit it.
            $own = is_string($p['setting'] ?? null) && isset($settings[$p['setting']]) && $p['setting'] !== $setting ? $p['setting'] : null;
            $takes[] = $this->take($request, $plan, $p, $products, (string) ($d['product'] ?? ''), $type, $title,
                $own ? $this->pickWardrobe($recentOutfits, $settings[$own]['wardrobe'] ?? []) : $wardrobe, $own ?? $setting, $composition);
        }

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
    private function take(Request $request, array $plan, array $p, array $products, string $product, string $type, string $title, string $wardrobe = '', ?string $setting = null, array $composition = []): array
    {
        $voice = $p['voice'] ?? null;
        $segments = array_map(function ($s) use ($voice, $p) {
            if (isset($p['speaker_voices'])) $voice = $p['speaker_voices'][$s['speaker'] ?? 0] ?? null;
            if ($voice && trim((string) ($s['script_text'] ?? '')) !== '') {
                $s['speed'] = self::PACE[$voice['pace'] ?? 'natural'] ?? 1.0;
                $s['voice_direction'] = trim((string) ($s['voice_direction'] ?? '').' '.(self::DELIVERY[$voice['delivery'] ?? 'friendly'] ?? self::DELIVERY['friendly']).'. '.(string) ($voice['direction'] ?? ''), ' .').'.';
            }
            return $s;
        }, $plan['segments']);
        $characterId = $p['character_id'] ?? null;
        $name = $characterId ? (string) self::castable($request->user())->where('id', $characterId)->value('name') : 'A fitting presenter';
        if ($type === 'skit') $name = implode(' + ', array_map(fn ($castId) => (string) self::castable($request->user())->where('id', $castId)->value('name'), $p['cast_character_ids'] ?? []));
        $oneShot = $composition !== [] || self::oneShotEligible($plan['format'], $segments) || UgcTypes::native($type, $request->user());
        // The type only travels where its route is open, so the compiler's per-type opening is not used before then.
        $liveType = UgcTypes::live($type, $request->user()) ? $type : null;
        if ($oneShot) {
            $v = array_filter(['ugc_type' => $liveType, 'composition' => $composition ?: null, 'cast_character_ids' => $p['cast_character_ids'] ?? null, 'format' => $plan['format'], 'segments' => $segments, 'script' => $plan['script'], 'character_id' => $characterId,
                'engine' => $characterId ? 'omni' : 'seedance25', 'quality' => 'full', 'product_asset_id' => $products[0] ?? null,
                'product_asset_ids' => $products ?: null, 'product' => mb_substr($product, 0, 200), 'language' => 'en',
                'presenter_description' => $characterId ? null : mb_substr((string) ($plan['presenter'] ?? ''), 0, 400), 'title' => $title, 'wardrobe' => $wardrobe,
                'setting_key' => $setting], fn ($x) => $x !== null && $x !== '');
            $price = UgcOneShotPricing::price($request->user(), $v, $segments, $this->credits);
            $v['credits'] = (int) $price['quote'];
            return ['character_id' => $characterId, 'name' => $name, 'picked' => ! empty($p['picked']), 'voice' => $voice, 'mode' => 'one_shot', 'wardrobe' => $wardrobe, 'setting' => $setting,
                'engine' => $price['engine'], 'credits' => (int) $price['quote'], 'request' => $v];
        }
        $per = UgcPlan::quote($segments);
        $v = array_filter(['ugc_type' => $liveType, 'format' => $plan['format'], 'segments' => $segments, 'script' => $plan['script'], 'character_ids' => $characterId ? [$characterId] : null,
            'aspect_ratio' => '9:16', 'language' => 'en', 'product_asset_id' => $products[0] ?? null, 'credits_per_character' => $per,
            'title' => $title], fn ($x) => $x !== null && $x !== '');
        return ['character_id' => $characterId, 'name' => $name, 'picked' => ! empty($p['picked']), 'voice' => $voice, 'mode' => 'composed',
            'engine' => 'composed', 'credits' => $per, 'request' => $v];
    }

    /**
     * The faces and outfits of this workspace's recent pitches, newest first.
     *
     * @return array{0: list<int>, 1: list<string>, 2: list<string>}
     */
    private function recentCasting(Request $request): array
    {
        $pitches = DB::table('ugc_chat_messages')->join('ugc_chats', 'ugc_chats.id', '=', 'ugc_chat_messages.chat_id')
            ->where('ugc_chats.workspace_id', $request->user()->workspace_id)->where('ugc_chat_messages.kind', 'pitch')
            ->orderByDesc('ugc_chat_messages.id')->limit(8)->pluck('ugc_chat_messages.payload_json');
        $faces = $outfits = $places = [];
        foreach ($pitches as $json) foreach ((json_decode((string) $json, true) ?: [])['takes'] ?? [] as $t) {
            if (! empty($t['character_id'])) $faces[] = (int) $t['character_id'];
            if (! empty($t['wardrobe'])) $outfits[] = (string) $t['wardrobe'];
            if (! empty($t['setting'])) $places[] = (string) $t['setting'];
        }
        return [array_values(array_slice(array_unique($faces), 0, 6)), array_values(array_unique($outfits)), array_values(array_slice(array_unique($places), 0, 4))];
    }

    private function pickWardrobe(array $recent, array $suited = []): string
    {
        $all = $suited ?: self::WARDROBE;
        $pool = array_values(array_diff($all, array_slice($recent, 0, 6))) ?: $all;
        return $pool[random_int(0, count($pool) - 1)];
    }

    /** A setting not used lately, when the director named none it can have. Null when no setting has a photo yet. */
    private function pickSetting(array $settings, array $recent): ?string
    {
        if (! $settings) return null;
        $pool = array_values(array_diff(array_keys($settings), $recent)) ?: array_keys($settings);
        return $pool[random_int(0, count($pool) - 1)];
    }

    /** The settings list the director picks from, with the ones used lately. */
    private function settingsText(Request $request): string
    {
        $settings = UgcSettings::available();
        if (! $settings) return '(none yet: leave "setting" empty and say where it is filmed in the summary)';
        $recent = $this->recentCasting($request)[2];
        return implode("\n", array_map(fn ($k, $s) => $k.': '.$s['place'], array_keys($settings), $settings))
            .($recent ? "\nUsed lately: ".implode(', ', $recent) : '');
    }

    /** Characters this workspace may cast: its own, and stock actors, active and with a reference photo. */
    private static function castable(User $user): \Illuminate\Database\Query\Builder
    {
        return DB::table('characters')->where('status', 'active')->whereNotNull('reference_asset_id')
            ->where(fn ($q) => $q->where('workspace_id', $user->workspace_id)->orWhere(fn ($q) => $q->whereNull('workspace_id')->where('is_stock', true)));
    }

    /** Whether every chosen presenter is the workspace's own character (never a stock actor, never "let us pick"). */
    private function ownFaces(Request $request, array $state): bool
    {
        $ids = array_map(fn ($p) => (int) ($p['character_id'] ?? 0), $state['presenters'] ?? []);
        if (! $ids || in_array(0, $ids, true)) return false;
        return DB::table('characters')->whereIn('id', $ids)->where('workspace_id', $request->user()->workspace_id)
            ->where(fn ($q) => $q->whereNull('is_stock')->orWhere('is_stock', false))->count() === count(array_unique($ids));
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
        // A product photo taken out after the pitch is not sent (one brought from their page may not be theirs to use).
        $kept = array_map(fn ($a) => (int) $a['asset_id'], (json_decode((string) $chat->state_json, true) ?: [])['attachments'] ?? []);
        foreach ($pitch['takes'] as $i => $t) {
            if (isset($pitch['started'][$i])) { $runs[] = $pitch['started'][$i]; continue; } // started by an earlier Make
            $products = array_values(array_filter(array_map('intval', (array) ($t['request']['product_asset_ids'] ?? array_filter([$t['request']['product_asset_id'] ?? null]))), fn ($p) => in_array($p, $kept, true)));
            foreach (['background_asset_id', 'left_asset_id', 'right_asset_id'] as $key) {
                $needed = $t['request']['composition'][$key] ?? null;
                if ($needed && ! in_array((int) $needed, $kept, true)) return $this->say($id, 'error', 'A required background or comparison file was removed. Request a new plan before making this video.');
            }
            unset($t['request']['product_asset_id'], $t['request']['product_asset_ids']);
            if ($products) $t['request'] += $t['mode'] === 'one_shot' ? ['product_asset_id' => $products[0], 'product_asset_ids' => $products] : ['product_asset_id' => $products[0]];
            // Keyed by the pitch, not the browser: a second Make after a reload is the same request (idempotent).
            $payload = $t['request'] + ['script' => '', 'consent' => true, 'reviewed' => true, 'request_id' => \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'wyv-ugc-chat:'.$messageId.':'.$i)->toString()];
            $out = $this->call($request, fn (UgcController $c, Request $r) => $t['mode'] === 'one_shot'
                ? app()->call([$c, 'generateOneShot'], ['request' => $r]) : app()->call([$c, 'generate'], ['request' => $r]), $payload);
            if (isset($out['error'])) { $failed = $out['error']['message'] ?? 'One video could not be started.'; break; }
            $runs[] = $pitch['started'][$i] = ['run_id' => $out['run_id'] ?? null, 'take_ids' => array_column($out['takes'] ?? [], 'id'), 'name' => $t['name'], 'credits' => (int) ($out['credits_quoted'] ?? $t['credits']), 'take' => $i];
            // Saved per take, so a failure on a later take never loses track of one already started.
            DB::table('ugc_chat_messages')->where('id', $messageId)->update(['payload_json' => json_encode($pitch)]);
        }
        if ($runs) {
            $pitch['made'] = true;
            DB::table('ugc_chat_messages')->where('id', $messageId)->update(['payload_json' => json_encode($pitch)]);
        }
        if (! $runs) return $this->say($id, 'error', $failed ?? 'The video could not be started.');
        return $this->say($id, 'making', $failed ? 'Started '.count($runs).' of '.count($pitch['takes']).'. '.$failed : 'Making it now.', ['pitch_id' => $messageId, 'runs' => $runs]);
    }

    /**
     * "Try again" on a video that failed. A one-take video resumes from the clip that failed (GenerateOneShotUgcJob);
     * otherwise the same pitch is made again and the failed take removed. A failed take was never charged (charge on
     * success), so a retry costs only what is delivered.
     */
    public function retry(Request $request, string $id, int $messageId, int $runIndex): array
    {
        $chat = $this->chat($request->user(), $id);
        $lock = \Illuminate\Support\Facades\Cache::lock('ugc-chat-retry:'.$messageId.':'.$runIndex, 120);
        if (! $lock->get()) abort(409, 'This video is already being made again.');
        try {
            $msg = DB::table('ugc_chat_messages')->where('chat_id', $chat->id)->where('id', $messageId)->where('kind', 'making')->first() ?? abort(404, 'Video not found.');
            $making = json_decode((string) $msg->payload_json, true) ?: [];
            $run = $making['runs'][$runIndex] ?? abort(404, 'Video not found.');
            $takes = $run['run_id'] ? ($this->call($request, fn (UgcController $c, Request $r) => app()->call([$c, 'takes'], ['request' => $r]), ['run' => $run['run_id']], 'GET')['takes'] ?? []) : [];
            if (! $takes || collect($takes)->contains(fn ($t) => ($t['status'] ?? '') !== 'needs_attention')) abort(409, 'Only a video that failed can be made again.');
            // A one-take video picks up from the clip that failed: same take, only the rest is made.
            $attempt = (int) ($run['attempt'] ?? 0) + 1;
            if (count($run['take_ids'] ?? []) === 1 && ($takes[0]['format'] ?? '') === 'one_shot') {
                $scene = \App\Models\Scene::query()->where('project_id', (int) $run['take_ids'][0])->orderBy('scene_order')->first();
                $resumed = $scene ? \App\Jobs\GenerateOneShotUgcJob::resume($scene) : 'not_resumable';
                if ($resumed === 'running') abort(409, 'This video is already being made.');
                if ($resumed === 'short') abort(402, 'Your balance can no longer cover this video. Top up, then try again; nothing was charged for the failed part.');
                if ($resumed === 'resumed') {
                    $making['runs'][$runIndex] = ['attempt' => $attempt, 'resumed' => true] + $run;
                    DB::table('ugc_chat_messages')->where('id', $messageId)->update(['payload_json' => json_encode($making)]);
                    return $this->show($request, $id);
                }
            }
            // A composed video paid for each finished shot as it was made: only its failed shots are made again
            // (remaking it whole charged those shots twice and deleted paid work).
            if (($takes[0]['format'] ?? '') !== 'one_shot') {
                $retried = 0;
                foreach ($run['take_ids'] ?? [] as $takeId) {
                    $detail = $this->call($request, fn (UgcController $c, Request $r) => app()->call([$c, 'takes'], ['request' => $r]), ['project_id' => (int) $takeId, 'detail' => 1], 'GET')['takes'][0] ?? null;
                    if (! empty($detail['working'])) abort(409, 'Part of this video is still being made. Try again once it stops.');
                    foreach ($detail['scene_rows'] ?? [] as $row) {
                        if (($row['visual'] ?? '') !== 'failed' && empty($row['error'])) continue;
                        $out = $this->call($request, fn (UgcController $c, Request $r) => app()->call([$c, 'sceneRetry'], ['request' => $r, 'sceneId' => (int) $row['id']]), []);
                        if (! isset($out['error'])) $retried++;
                    }
                }
                if (! $retried) abort(422, 'Nothing in this video can be made again from here. Open it to fix the failed part.');
                $making['runs'][$runIndex] = ['attempt' => $attempt] + $run;
                DB::table('ugc_chat_messages')->where('id', $messageId)->update(['payload_json' => json_encode($making)]);
                return $this->show($request, $id);
            }
            $pitch = json_decode((string) DB::table('ugc_chat_messages')->where('chat_id', $chat->id)->where('id', (int) ($making['pitch_id'] ?? 0))->value('payload_json'), true) ?: [];
            $i = $run['take'] ?? collect($pitch['takes'] ?? [])->search(fn ($t) => $t['name'] === $run['name']);
            if ($i === false || $i === null) abort(404, 'The pitch for this video is gone.');
            $t = $pitch['takes'][$i] ?? abort(404, 'The pitch for this video is gone.');
            $payload = $t['request'] + ['script' => '', 'consent' => true, 'reviewed' => true, 'request_id' => \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'wyv-ugc-chat-retry:'.$messageId.':'.$runIndex.':'.$attempt)->toString()];
            $out = $this->call($request, fn (UgcController $c, Request $r) => $t['mode'] === 'one_shot'
                ? app()->call([$c, 'generateOneShot'], ['request' => $r]) : app()->call([$c, 'generate'], ['request' => $r]), $payload);
            if (isset($out['error'])) abort(422, $out['error']['message'] ?? 'It could not be started again.');
            // The failed take goes; nothing was charged for it.
            foreach ($run['take_ids'] ?? [] as $old) {
                try { $this->call($request, fn ($c, Request $r) => app()->call([app(\App\Http\Controllers\Api\V1\Project\ProjectController::class), 'destroy'], ['request' => $r, 'projectId' => (int) $old]), [], 'DELETE'); }
                catch (\Throwable $e) { report($e); }
            }
            $making['runs'][$runIndex] = ['run_id' => $out['run_id'] ?? null, 'take_ids' => array_column($out['takes'] ?? [], 'id'), 'name' => $run['name'],
                'credits' => (int) ($out['credits_quoted'] ?? $t['credits']), 'take' => $i, 'attempt' => $attempt];
            DB::table('ugc_chat_messages')->where('id', $messageId)->update(['payload_json' => json_encode($making)]);
            return $this->show($request, $id);
        } finally {
            $lock->release();
        }
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
            // Photos brought from their product page, shown under what we read there.
            if ($m->kind === 'facts') foreach ($payload['facts'] ?? [] as $i => $f) {
                $payload['facts'][$i]['images'] = array_map(fn ($assetId) => ['asset_id' => (int) $assetId,
                    'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute('media.assets.content', now()->addHours(2), ['assetId' => (int) $assetId])], $f['images'] ?? []);
            }
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

    /** Take a file out of what the chat uses (a product photo brought from their page that is not theirs to use, or wrong). */
    public function removeAttachment(User $user, string $id, int $assetId): array
    {
        $chat = $this->chat($user, $id);
        $state = json_decode((string) $chat->state_json, true) ?: [];
        $state['attachments'] = array_values(array_filter($state['attachments'] ?? [], fn ($a) => (int) $a['asset_id'] !== $assetId));
        DB::table('ugc_chats')->where('id', $id)->update(['state_json' => json_encode($state), 'updated_at' => now()]);

        return $state;
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
        foreach ($s['attachments'] ?? [] as $a) {
            $seen = ! empty($a['look']) ? (($a['shot'] ?? 'product') === 'other' ? ' (not a product shot: '.$a['look'].')' : ' showing '.$a['look']) : '';
            $lines[] = "- {$a['role']} {$a['type']}".(! empty($a['from_link']) ? ' from their product page' : '').": {$a['title']}{$seen}";
        }
        if (! empty($s['link']['facts'])) $lines[] = '- Product page '.$s['link']['url'].': '.json_encode(array_intersect_key($s['link']['facts'], array_flip(['product', 'context', 'must_include'])));
        elseif (! empty($s['link']['url'])) $lines[] = '- Product page '.$s['link']['url'].' (could not be read)';
        if (! empty($s['sample']['reference'])) $lines[] = '- Sample UGC structure (copy its shape, never its words): '.json_encode($s['sample']['reference']);
        return $lines ? implode("\n", $lines) : '(nothing yet)';
    }

    private function presentersText(array $s, int $workspaceId): string
    {
        $out = [];
        foreach ($s['presenters'] ?? [] as $p) {
            $name = ! empty($p['character_id']) ? (string) DB::table('characters')->where('id', $p['character_id'])->where(fn ($q) => $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId))->value('name') : 'let us pick';
            $v = $p['voice'] ?? null;
            $place = ! empty($p['setting']) ? (UgcSettings::all()[$p['setting']]['place'] ?? null) : null;
            $out[] = $name.($v ? ' (voice: '.($v['pace'] ?? 'natural').' pace, '.($v['delivery'] ?? 'friendly').(! empty($v['direction']) ? ', '.$v['direction'] : '').')' : ' (voice: their default)')
                .($place ? ' (filmed in '.$place.', the user chose it)' : '');
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // A refusal such as "our video service is down" reaches the chat as said, not as a crash.
            return ['error' => ['code' => 'refused', 'message' => $e->getMessage() ?: 'The request was refused.']];
        } catch (\Throwable $e) {
            report($e);
            return ['error' => ['code' => 'failed', 'message' => 'Something went wrong on our side. Nothing was charged for it; try again.']];
        }
        $body = $response->getData(true);
        if ($response->getStatusCode() >= 400) return ['error' => $body['error'] ?? ['code' => 'ugc_refused', 'message' => 'The request was refused.']];
        return $body['data'] ?? [];
    }
}

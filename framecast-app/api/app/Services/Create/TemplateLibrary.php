<?php

namespace App\Services\Create;

use App\Models\{ApiQuote, Asset, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Cache, DB, Http, URL};
use Illuminate\Support\Str;

/**
 * Templates (owner, 2026-10-16): finished videos anyone can start from, in one library with Create's examples. A
 * template is a reference like any other: the user picks how closely to follow it (exactly, similar, inspired) and the
 * Creative Director follows that choice. Following a template we have the code of "exactly" starts the user's
 * conversation from a copy of that version, so the brief is a change to a finished video; any other choice, or a
 * template that is a video only, attaches the template's video as the reference.
 *
 * The page is staff-only until create.templates_public; the examples already on Create's empty screen stay there.
 */
class TemplateLibrary
{
    public const CATEGORIES = ['offer' => 'Offer ads', 'app' => 'App showcases', 'explainer' => 'Explainers', 'ugc' => 'UGC ads',
        'announce' => 'Announcements', 'footage' => 'From your footage'];
    public const MATCHES = ['exact', 'similar', 'inspired'];
    private const BASE = 'https://s3.us-east-005.backblazeb2.com/frame-cast/marketing/samples/';

    public static function isStaff(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'platform_admin'], true);
    }

    /** Whether this user sees the Templates page at all. */
    public static function pageOpen(User $user): bool
    {
        return self::isStaff($user) || (bool) config('create.templates_public');
    }

    /** Templates this user may see, in library order, with whether the Launchpad vault opens each one. */
    public function visible(User $user): array
    {
        $rows = DB::table('create_templates')->whereNull('archived_at')
            ->when(! self::pageOpen($user), fn ($q) => $q->where('on_samples', true))->orderBy('position')->get();
        // A plan that names a vault size (the Launchpad Bundle's done-for-you templates) opens that many, in order.
        $vault = app(\App\Services\CreditService::class)->limitFor((int) $user->workspace_id, 'sample_vault');
        return $rows->values()->map(fn ($t, $i) => [...self::card($t), 'locked' => $vault !== null && $i >= (int) $vault])->all();
    }

    /** The page's list: staff-only until public. */
    public function page(User $user): array
    {
        abort_unless(self::pageOpen($user), 404);
        return ['templates' => $this->visible($user), 'categories' => self::CATEGORIES, 'staff' => self::isStaff($user),
            'public' => (bool) config('create.templates_public')];
    }

    public function show(User $user, string $id): array
    {
        $t = $this->find($user, $id);
        $card = collect($this->visible($user))->firstWhere('id', $t->id);
        return [...$card, 'description' => $t->description, 'ask_hint' => $t->ask_hint, 'parts' => self::summary(json_decode((string) $t->parts_json, true) ?: []),
            'matches' => self::matchOptions(), 'staff' => self::isStaff($user), 'public' => (bool) config('create.templates_public'),
            ...(self::isStaff($user) ? ['conversation_id' => $t->conversation_id, 'revision_id' => $t->revision_id, 'parts_full' => json_decode((string) $t->parts_json, true)] : [])];
    }

    /** What the user is choosing between, in the words Create already uses (ReferenceMatch). */
    public static function matchOptions(): array
    {
        return [
            ['value' => 'exact', 'label' => 'Exactly', 'hint' => 'The same video, with your product, words, colours and photos in it.'],
            ['value' => 'similar', 'label' => 'Similar', 'hint' => 'Its format, look and pacing, telling your own story.'],
            ['value' => 'inspired', 'label' => 'Inspired', 'hint' => 'Just the idea; the Creative Director makes something of its own.'],
        ];
    }

    /** "Add to templates" (staff): a finished version becomes a template, with its parts read from the code. */
    public function add(User $user, string $conversationId, string $revisionId, array $fields): array
    {
        abort_unless(self::isStaff($user), 403);
        // Our own builds only, never a customer's video.
        abort_unless(DB::table('create_conversations')->where('id', $conversationId)->where('workspace_id', $user->workspace_id)->exists(), 404);
        $revision = DB::table('composition_revisions')->where('conversation_id', $conversationId)->where('id', $revisionId)->firstOrFail();
        abort_unless($revision->artifact_path && str_ends_with((string) $revision->artifact_path, '.mp4'), 422, 'Only a finished video can become a template.');
        $id = 't'.Str::lower(Str::random(9));
        $parts = self::partsOf($revision);
        DB::table('create_templates')->insert([
            'id' => $id, 'title' => $fields['title'], 'category' => $fields['category'], 'description' => $fields['description'] ?? null,
            'ask_hint' => $fields['ask_hint'] ?? null, 'style' => $fields['style'] ?? $parts['style'] ?? null,
            'shape' => $parts['shape'] ?? '9:16', 'seconds' => (int) round($parts['seconds'] ?? 15),
            'position' => 10 + (int) DB::table('create_templates')->max('position'), 'source' => 'version',
            'conversation_id' => $conversationId, 'revision_id' => $revisionId, 'parts_json' => json_encode($parts),
            'on_samples' => false, 'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->show($user, $id);
    }

    /** What "Add to templates" fills in before staff edit it: the parts and the defaults read from the version. */
    public function draft(User $user, string $conversationId, string $revisionId): array
    {
        abort_unless(self::isStaff($user), 403);
        // Our own builds only, never a customer's video.
        abort_unless(DB::table('create_conversations')->where('id', $conversationId)->where('workspace_id', $user->workspace_id)->exists(), 404);
        $revision = DB::table('composition_revisions')->where('conversation_id', $conversationId)->where('id', $revisionId)->firstOrFail();
        $parts = self::partsOf($revision);
        $existing = DB::table('create_templates')->where('revision_id', $revisionId)->whereNull('archived_at')->value('id');
        return ['parts' => $parts, 'summary' => self::summary($parts), 'categories' => self::CATEGORIES, 'existing_id' => $existing,
            'title' => mb_substr((string) DB::table('create_conversations')->where('id', $conversationId)->value('title'), 0, 120)];
    }

    /**
     * "Write it for me" in the staff drawer: one small model call drafts the title, category, description and what the user
     * is asked for, from the version's brief, plan and parts. Staff edit what comes back; nothing is saved here.
     */
    public function suggest(User $user, string $conversationId, string $revisionId): array
    {
        abort_unless(self::isStaff($user), 403);
        abort_unless(DB::table('create_conversations')->where('id', $conversationId)->where('workspace_id', $user->workspace_id)->exists(), 404);
        $revision = DB::table('composition_revisions')->where('conversation_id', $conversationId)->where('id', $revisionId)->firstOrFail();
        $parts = self::partsOf($revision);
        $run = $revision->run_id ? json_decode((string) DB::table('composition_runs')->where('id', $revision->run_id)->value('input_json'), true) : [];
        $plan = $run['plan'] ?? [];
        $brief = (string) DB::table('create_messages')->where('conversation_id', $conversationId)->where('role', 'user')->orderBy('sequence')->value('content');
        $facts = ['brief' => mb_substr($brief, 0, 1500), 'plan_summary' => mb_substr((string) ($plan['summary'] ?? ''), 0, 600),
            'scenes' => array_slice(array_map(fn ($s) => $s['what'], $parts['scenes']), 0, 10), 'text' => array_map(fn ($t) => $t['label'].': '.$t['default'], $parts['text']),
            'pictures' => array_map(fn ($i) => $i['what'], $parts['images']), 'voiceover' => (bool) $parts['voice'], 'music' => (bool) $parts['music'],
            'clips' => array_map(fn ($c) => $c['what'], $parts['clips']), 'style' => $parts['style'], 'shape' => $parts['shape'], 'seconds' => $parts['seconds']];
        $prompt = "A finished WyvStudio video is becoming a template: other people will make the same video for their own product. Write the template's listing.\n\n"
            ."The video, as made for its original product:\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n\n"
            ."Return JSON only: {\"title\": ..., \"category\": ..., \"description\": ..., \"ask_hint\": ...}\n"
            ."- title: what the video is, for any product, at most 50 characters, no product or brand names from the original (\"Sneaker drop with an offer\", \"App tour in three features\").\n"
            ."- category: one of ".implode(', ', array_keys(self::CATEGORIES))." (offer = product or sale ad, app = app or software showcase, explainer, ugc = a person talking to camera, announce = launch or news, footage = cut from the user's own video).\n"
            ."- description: one or two plain sentences on what happens in the video and what it suits, for any product: name the beats and the look, not the original product. At most 220 characters.\n"
            ."- ask_hint: the placeholder for the box where the user describes their product. Start with what to give (\"A link, or a few words about it.\"), then name what this template needs that a link might not give (an offer, app screens, a price), then a short example after \"e.g.\". At most 140 characters.\n"
            ."Plain words, no hype, no exclamation marks.";
        $key = (string) config('services.anthropic.key');
        abort_if($key === '', 503, 'Suggestions are not available right now.');
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(45)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'),
                    'max_tokens' => 1200, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
        } catch (\Throwable) { abort(503, 'Could not write a suggestion just now. Try again.'); }
        abort_unless($r->successful(), 503, 'Could not write a suggestion just now. Try again.');
        $out = \App\Services\Ugc\UgcPlan::decodeModelJson(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('')) ?: [];
        return array_filter(['title' => mb_substr(trim((string) ($out['title'] ?? '')), 0, 120),
            'category' => isset(self::CATEGORIES[$out['category'] ?? '']) ? $out['category'] : null,
            'description' => mb_substr(trim((string) ($out['description'] ?? '')), 0, 600), 'ask_hint' => mb_substr(trim((string) ($out['ask_hint'] ?? '')), 0, 300)]);
    }

    public function update(User $user, string $id, array $fields): array
    {
        abort_unless(self::isStaff($user), 403);
        abort_unless(DB::table('create_templates')->where('id', $id)->whereNull('archived_at')->exists(), 404);
        DB::table('create_templates')->where('id', $id)->update([...$fields, 'updated_at' => now()]);
        return $this->show($user, $id);
    }

    public function archive(User $user, string $id): void
    {
        abort_unless(self::isStaff($user), 403);
        DB::table('create_templates')->where('id', $id)->update(['archived_at' => now(), 'updated_at' => now()]);
    }

    /**
     * "Make mine": a new conversation from the template, following the chosen option. Returns the conversation and the
     * brief to send once the user's photos are attached, so the plan reads them with the brief.
     */
    public function use(User $user, string $id, array $input): array
    {
        $conversations = app(ConversationService::class);
        $conversations->authorize($user, true);
        $t = $this->find($user, $id);
        abort_if(collect($this->visible($user))->firstWhere('id', $t->id)['locked'] ?? false, 403, 'This template is not in your plan.');
        $match = in_array($input['match'] ?? null, self::MATCHES, true) ? $input['match'] : 'exact';
        $key = 'create-template-use:'.$user->workspace_id.':'.$input['idempotency_key'];
        if ($done = Cache::get($key)) return $done;
        return Cache::lock($key.':lock', 120)->block(60, function () use ($conversations, $user, $t, $match, $input, $key) {
            if ($done = Cache::get($key)) return $done;
            $settings = ['aspect_ratio' => $input['aspect_ratio'] ?? $t->shape, 'duration_seconds' => max(5, min(30, (int) $t->seconds)),
                'reference_match' => $match, 'template_id' => $t->id];
            $c = $conversations->create($user, $settings);
            DB::table('create_conversations')->where('id', $c->id)->update(['title' => mb_substr('From template: '.$t->title, 0, 120)]);
            $copy = $match === 'exact' && $t->source === 'version' && $t->revision_id
                && DB::table('composition_revisions')->where('id', $t->revision_id)->exists();
            if ($copy) $this->copyVersion($user, $c->id, $t);
            // A free plan's first "Exactly" from a template with its own code is its free first ad (FreeFirst).
            if ($copy && FreeFirst::templateFits($t)) FreeFirst::claim((int) $user->workspace_id, $c->id);
            else $this->attachReference($user, $c->id, $t, (int) DB::table('create_conversations')->where('id', $c->id)->value('version'));
            $done = ['id' => $c->id, 'version' => (int) DB::table('create_conversations')->where('id', $c->id)->value('version'),
                'brief' => self::brief($t, $copy ? 'version' : $match, (string) $input['product'], $copy && FreeFirst::freePlan((int) $user->workspace_id) && FreeFirst::templateFits($t))];
            Cache::put($key, $done, now()->addDay());
            return $done;
        });
    }

    /** The brief "Make mine" sends: the user's own words, then what the template keeps and what it swaps. */
    public static function brief(object $t, string $how, string $product, bool $free = false): string
    {
        $product = trim($product);
        if ($how === 'similar') return "Make a video like the template \"{$t->title}\" for my product: {$product}\n\nKeep its format, look and pacing, and tell my own story with my own shots. Use my attached photos where they fit.";
        if ($how === 'inspired') return "Use the template \"{$t->title}\" as inspiration for a video about my product: {$product}\n\nTake its idea and make something of your own.";
        if ($how !== 'version') return "Make the template \"{$t->title}\" for my product, following it exactly: {$product}\n\nKeep its timing, layout and transitions, with my brand and content swapped in. Use my attached photos where it shows the product.";
        $parts = json_decode((string) $t->parts_json, true) ?: [];
        $lines = ["Make this template for my product: {$product}", '',
            'Follow it exactly: keep the layout, every move and its timing, the cuts, the style'.(! empty($parts['style']) ? ' ('.$parts['style'].')' : '')
            .(! empty($parts['fonts']) ? ', the fonts ('.implode(', ', $parts['fonts']).')' : '').' and the length. Swap only what is about the product:'];
        if (! empty($parts['text'])) $lines[] = '- Text: '.implode('; ', array_map(fn ($p) => $p['label'].' "'.$p['default'].'"', $parts['text'])).'. Write mine in the same roles and about the same length.';
        if (! empty($parts['colours'])) $lines[] = '- Colours: '.implode('; ', array_map(fn ($p) => $p['label'].' '.$p['default'], $parts['colours'])).'. Use my brand colours in these roles if I have them and the words stay easy to read; otherwise keep these.';
        if (! empty($parts['images'])) $lines[] = '- Pictures of the product: '.implode('; ', array_map(fn ($p) => $p['what'], $parts['images'])).'. Use my attached photos there; '
            .($free ? 'keep the template\'s own picture where I gave none (this is my free ad: nothing is generated).' : 'make a new picture only where I gave none.');
        if (! empty($parts['voice'])) $lines[] = '- Voiceover: re-voice it with my words, same voice and pacing.';
        if (! empty($parts['music'])) $lines[] = '- Music: keep the track as it is.';
        if (! empty($parts['clips'])) $lines[] = '- Video clips: '.implode('; ', array_map(fn ($p) => $p['what'], $parts['clips'])).'. Make them new only if they show the old product.';
        if (! empty($parts['footage'])) $lines[] = '- Footage of the template\'s own product: '.implode('; ', array_map(fn ($p) => $p['what'], $parts['footage']))
            .'. This shows the old product: put my screen recording or footage of my product in its place, sized and timed the same, and keep everything around it as it is (a reaction or presenter stays). If I attached none, ask me for it before planning.';
        return implode("\n", $lines);
    }

    /**
     * The parts a version is made of, read from its code and the record of what its build bought: the text and colours
     * it declares as variables, its fonts, its pictures and what each shows, its voiceover, music and clips.
     */
    public static function partsOf(object $revision): array
    {
        $bundle = json_decode((string) $revision->bundle_json, true) ?: [];
        $html = (string) ($bundle['index.html'] ?? '');
        $code = implode("\n", array_filter($bundle, 'is_string'));
        $vars = CompositionVariables::declarations($html);
        $text = array_values(array_map(fn ($v) => ['id' => $v['id'], 'label' => $v['label'], 'default' => (string) ($v['default'] ?? ''),
            'max' => max(4, (int) ceil(mb_strlen((string) ($v['default'] ?? '')) * 1.25))], array_filter($vars, fn ($v) => $v['type'] === 'string')));
        $colours = array_values(array_map(fn ($v) => ['id' => $v['id'], 'label' => $v['label'], 'default' => (string) ($v['default'] ?? '')],
            array_filter($vars, fn ($v) => $v['type'] === 'color')));
        preg_match_all('/@font-face\s*\{[^}]*?font-family\s*:\s*[\'"]?([^;\'"}]+)/i', $code, $f);
        $fonts = array_values(array_unique(array_map('trim', $f[1])));

        $run = $revision->run_id ? DB::table('composition_runs')->where('id', $revision->run_id)->first() : null;
        $input = $run ? (json_decode((string) $run->input_json, true) ?: []) : [];
        $files = collect(app(InputSnapshotService::class)->inherited($revision->conversation_id, $revision->id))->keyBy('asset_id');
        // What each bought file is: the plan's item that made it, read through create_plan_media.
        $bought = [];
        foreach (DB::table('create_plan_media')->where('conversation_id', $revision->conversation_id)->where('status', 'succeeded')->get() as $m) {
            $r = json_decode((string) $m->record_json, true) ?: [];
            if ($assetId = data_get($r, 'file.asset_id')) $bought[(int) $assetId] = ['kind' => $m->kind, 'what' => (string) ($r['description'] ?? $m->kind), 'credits' => (int) $m->charged_credits];
        }
        $describe = function (int $assetId) use ($files, $bought) {
            if (isset($bought[$assetId])) return $bought[$assetId];
            $from = (int) ($files[$assetId]['derived_from_asset_id'] ?? 0);
            if ($from && isset($bought[$from])) return [...$bought[$from], 'kind' => $bought[$from]['kind'].'+'.($files[$assetId]['operation'] ?? 'edit')];
            return ['kind' => ($files[$assetId]['purpose'] ?? '') === 'source' ? 'upload' : 'file', 'what' => (string) ($files[$assetId]['title'] ?? 'a picture'), 'credits' => 0];
        };
        $images = [];
        preg_match_all('/<img\b[^>]*>/i', $html, $tags);
        foreach ($tags[0] as $tag) {
            if (! preg_match('/src="asset-(\d+)-/', $tag, $s)) continue;
            preg_match('/\sid="([^"]+)"/', $tag, $idm);
            $d = $describe((int) $s[1]);
            $images[] = ['element' => $idm[1] ?? null, 'asset_id' => (int) $s[1], 'what' => $d['what'], 'kind' => $d['kind']];
        }
        $plan = $input['plan'] ?? [];
        $media = collect($input['plan_media'] ?? []);
        $voice = $media->firstWhere('kind', 'voiceover');
        $music = $media->firstWhere('kind', 'music');
        $clips = $media->filter(fn ($m) => in_array($m['kind'] ?? '', ['generated_shot', 'animate_image', 'ugc_take', 'talking_take', 'talking_shot', 'stock_video', 'ai_video', 'broll'], true))
            ->map(fn ($m) => ['kind' => $m['kind'], 'what' => (string) ($m['description'] ?? $m['kind']), 'credits' => (int) ($m['credits'] ?? 0)])->values()->all();
        // Footage the code plays that the build did not make: a screen recording on a phone, the user's own clip. It shows
        // the template's own product, so the brief says to replace it (owner, 2026-10-17: an app demo's recording was not
        // listed, and a free change swapped only the words around the old product's demo).
        $made = array_keys(array_filter($bought, fn ($b) => in_array($b['kind'], ['generated_shot', 'animate_image', 'ugc_take', 'talking_take', 'talking_shot', 'stock_video', 'ai_video', 'broll'], true)));
        $footage = [];
        preg_match_all('/asset-(\d+)-[a-f0-9]{16,64}\.(?:mp4|mov|webm|m4v)/i', $code, $vids);
        foreach (array_unique(array_map('intval', $vids[1])) as $assetId) {
            $from = (int) ($files[$assetId]['derived_from_asset_id'] ?? 0);
            if (in_array($assetId, $made, true) || ($from && in_array($from, $made, true))) continue;
            $d = $describe($assetId);
            $footage[] = ['asset_id' => $assetId, 'what' => $d['what'] === 'a picture' ? 'a video clip' : $d['what'], 'kind' => $d['kind']];
        }
        // Text fields the code never reads (a swap changes nothing on screen), and WyvStudio's own name written into the
        // code where no field can change it (owner's free-ad test, 2026-10-17: the end card kept wyvstudio.com).
        $rest = preg_replace('/data-composition-variables=(\'[^\']*\'|"[^"]*")/', '', $code);
        $dead = array_values(array_map(fn ($v) => $v['id'], array_filter($vars, fn ($v) => in_array($v['type'], ['string', 'color'], true)
            && ! preg_match('/\b'.preg_quote((string) $v['id'], '/').'\b/', $rest))));
        $uncommented = preg_replace(['#/\*.*?\*/#s', '#(?<![:"\'])//[^\n]*#', '#<!--.*?-->#s'], '', $rest);
        $fixedBrand = (bool) preg_match('/wyv\s*studio|wyvstudio/i', (string) $uncommented);
        // A dead field is not offered as a part: changing it would change nothing.
        $text = array_values(array_filter($text, fn ($p) => ! in_array($p['id'], $dead, true)));
        $colours = array_values(array_filter($colours, fn ($p) => ! in_array($p['id'], $dead, true)));
        $settings = $input['settings'] ?? [];
        return [
            'text' => $text, 'colours' => $colours, 'fonts' => $fonts, 'images' => $images, 'footage' => $footage,
            'dead_fields' => $dead, 'fixed_brand' => $fixedBrand,
            'voice' => $voice ? ['what' => (string) ($voice['description'] ?? ''), 'voice' => $plan['voice'] ?? null, 'lines' => array_values(array_filter((array) ($plan['narration'] ?? []), 'is_string')), 'credits' => (int) ($voice['credits'] ?? 0)] : null,
            'music' => $music ? ['what' => (string) ($music['description'] ?? ''), 'credits' => (int) ($music['credits'] ?? 0)] : null,
            'clips' => $clips,
            'scenes' => array_values(array_map(fn ($s) => ['from' => $s['from'] ?? null, 'to' => $s['to'] ?? null, 'what' => (string) ($s['what'] ?? $s['purpose'] ?? '')], (array) ($plan['scenes'] ?? []))),
            'style' => data_get($input, 'style_pack.name') ?? data_get($input, 'style_pack.slug') ?? ($settings['style_pack'] ?? null),
            'shape' => $settings['aspect_ratio'] ?? '9:16', 'seconds' => (float) ($settings['duration_seconds'] ?? 15),
            'paid' => array_values(array_map(fn ($m) => ['kind' => $m['kind'] ?? '', 'what' => (string) ($m['description'] ?? ''), 'credits' => (int) ($m['credits'] ?? 0)], $media->all())),
        ];
    }

    /** The parts in a line each, for the template page and the staff drawer. */
    public static function summary(array $parts): array
    {
        $out = [];
        if (! empty($parts['text'])) $out[] = count($parts['text']).(count($parts['text']) === 1 ? ' line of text' : ' lines of text');
        if (! empty($parts['colours'])) $out[] = count($parts['colours']).(count($parts['colours']) === 1 ? ' brand colour' : ' brand colours');
        if (! empty($parts['images'])) $out[] = count($parts['images']).' product '.(count($parts['images']) === 1 ? 'picture' : 'pictures');
        if (! empty($parts['voice'])) $out[] = 'Voiceover';
        if (! empty($parts['music'])) $out[] = 'Music';
        if (! empty($parts['clips'])) $out[] = count($parts['clips']).' video '.(count($parts['clips']) === 1 ? 'clip' : 'clips');
        if (! empty($parts['footage'])) $out[] = count($parts['footage']).' product '.(count($parts['footage']) === 1 ? 'recording' : 'recordings').' to replace';
        return $out;
    }

    private function find(User $user, string $id): object
    {
        $t = DB::table('create_templates')->where('id', $id)->whereNull('archived_at')->first();
        abort_unless($t && (self::pageOpen($user) || $t->on_samples), 404, 'That template is no longer available.');
        return $t;
    }

    /** A template as a card: the examples keep their public poster, hover preview and video; a version streams signed. */
    public static function card(object $t): array
    {
        if ($t->sample_folder) {
            $media = ['poster_url' => self::BASE.'thumbs/'.$t->id.'.webp', 'preview_url' => self::BASE.'previews/'.$t->id.'.mp4', 'video_url' => self::BASE.$t->sample_folder.'/'.$t->id.'.mp4'];
        } else {
            $video = $t->revision_id ? URL::temporarySignedRoute('media.create.version', now()->addHours(3), ['revisionId' => $t->revision_id]) : null;
            $media = ['poster_url' => $t->revision_id ? URL::temporarySignedRoute('media.create.poster', now()->addHours(3), ['revisionId' => $t->revision_id]) : null,
                'preview_url' => $video, 'video_url' => $video];
        }
        return ['id' => $t->id, 'title' => $t->title, 'category' => $t->category, 'category_label' => self::CATEGORIES[$t->category] ?? $t->category,
            'style' => $t->style, 'shape' => $t->shape, 'seconds' => (int) $t->seconds, 'source' => $t->source, 'on_samples' => (bool) $t->on_samples,
            'free_fit' => FreeFirst::templateFits($t), ...$media];
    }

    /**
     * "Exactly" from a version: the template's version becomes version 1 of the user's conversation. Its files are copied
     * into the user's workspace (new assets, renamed in the code), so the next build reads them as its own.
     */
    private function copyVersion(User $user, string $conversationId, object $t): void
    {
        $revision = DB::table('composition_revisions')->where('id', $t->revision_id)->firstOrFail();
        $sourceRun = $revision->run_id ? DB::table('composition_runs')->where('id', $revision->run_id)->first() : null;
        $sourceInput = $sourceRun ? (json_decode((string) $sourceRun->input_json, true) ?: []) : [];
        $storage = app(CreateStorage::class);
        $bundle = json_decode((string) $revision->bundle_json, true) ?: [];
        $copied = [];
        $code = implode("\n", array_filter($bundle, 'is_string'));
        // Only the files the template's code uses: a reference studied for the original, or a picture it dropped, stays behind.
        foreach (app(InputSnapshotService::class)->inherited($revision->conversation_id, $revision->id) as $f) {
            if (! str_contains($code, (string) $f['name'])) continue;
            $ext = pathinfo((string) $f['name'], PATHINFO_EXTENSION);
            $suffix = $user->workspace_id.'/'.Str::uuid().'/'.$f['sha256'].'.'.$ext;
            $path = 'create/uploads/'.$suffix;
            $stream = $storage->readStream($f['storage_path']);
            abort_unless(is_resource($stream), 503, 'The template\'s media could not be read just now. Try again shortly.');
            try { abort_unless($storage->put($path, $stream, ['visibility' => 'private']), 503, 'Could not copy the template\'s media.'); }
            finally { if (is_resource($stream)) fclose($stream); }
            $asset = Asset::create(['workspace_id' => $user->workspace_id, 'created_by_user_id' => $user->id, 'asset_type' => $f['asset_type'],
                'title' => mb_substr('Template · '.((string) ($f['title'] ?? '') ?: $t->title), 0, 180), 'storage_url' => 'create-upload://'.$suffix,
                'mime_type' => $f['mime_type'], 'file_size_bytes' => (int) $f['bytes'], 'duration_seconds' => $f['duration_seconds'] ?? null,
                'status' => 'active', 'restriction_scope' => 'workspace',
                'metadata_json' => ['create_template_id' => $t->id, 'copied_from_asset_id' => (int) $f['asset_id'], 'create_conversation_id' => $conversationId]]);
            $name = 'asset-'.$asset->id.'-'.$f['sha256'].'.'.$ext;
            $copied[] = [...$f, 'asset_id' => (int) $asset->id, 'name' => $name, 'storage_path' => $path, 'purpose' => 'source'];
            foreach ($bundle as $file => $contents) if (is_string($contents)) $bundle[$file] = str_replace((string) $f['name'], $name, $contents);
        }
        $runId = (string) Str::uuid();
        $artifact = null;
        if ($revision->artifact_path && $storage->exists($revision->artifact_path)) {
            $artifact = 'create/previews/'.$runId.'/'.basename((string) $revision->artifact_path);
            $stream = $storage->readStream($revision->artifact_path);
            try { if (is_resource($stream)) $storage->put($artifact, $stream, ['visibility' => 'private']); else $artifact = null; }
            finally { if (is_resource($stream)) fclose($stream); }
        }
        DB::transaction(function () use ($user, $conversationId, $t, $revision, $sourceInput, $copied, $bundle, $runId, $artifact) {
            $c = DB::table('create_conversations')->where('id', $conversationId)->lockForUpdate()->first();
            $quote = ApiQuote::create(['id' => ApiQuote::newId(), 'workspace_id' => $user->workspace_id, 'created_by_user_id' => $user->id,
                'credits_min' => 0, 'credits_max' => 0, 'expires_at' => now(), 'consumed_at' => now(), 'payload_json' => ['kind' => 'template_copy', 'template_id' => $t->id]]);
            $input = ['kind' => 'template_copy', 'conversation_id' => $conversationId, 'template_id' => $t->id, 'version' => (int) $c->version,
                'input_files' => $copied, 'derived_files' => [], 'plan' => $sourceInput['plan'] ?? null, 'style' => $sourceInput['style'] ?? null,
                'style_pack' => $sourceInput['style_pack'] ?? null, 'settings' => json_decode((string) $c->settings_json, true), 'mode' => config('create.mode')];
            DB::table('composition_runs')->insert(['id' => $runId, 'conversation_id' => $conversationId, 'workspace_id' => $user->workspace_id,
                'quote_id' => $quote->id, 'idempotency_key' => 'template:'.$conversationId, 'request_hash' => hash('sha256', json_encode($input)),
                'input_json' => json_encode($input), 'status' => 'preview_ready', 'stage' => 'Started from the template "'.$t->title.'"',
                'created_at' => now(), 'updated_at' => now()]);
            // The template's own requirements are about its old product; the user's brief sets new ones.
            $metadata = json_decode((string) ($revision->metadata_json ?? '{}'), true) ?: [];
            // The template's own build notes (its review, checks and suggestions) are not the user's to act on.
            unset($metadata['requirements'], $metadata['requirement_history'], $metadata['creative_review'], $metadata['review'], $metadata['final_checks'],
                $metadata['delivery_checks'], $metadata['media_suggestions']);
            $new = (string) Str::uuid();
            DB::table('composition_revisions')->insert(['id' => $new, 'conversation_id' => $conversationId, 'run_id' => $runId, 'number' => 1,
                'bundle_json' => json_encode($bundle), 'bundle_hash' => hash('sha256', json_encode($bundle)),
                'artifact_path' => $artifact, 'artifact_hash' => $artifact ? $revision->artifact_hash : null,
                'metadata_json' => json_encode([...$metadata, 'template_id' => $t->id]), 'summary' => 'The template "'.$t->title.'", before your changes.', 'created_at' => now()]);
            DB::table('create_conversations')->where('id', $conversationId)->update(['head_revision_id' => $new, 'version' => $c->version + 1, 'updated_at' => now()]);
        });
    }

    /** The template's video as this conversation's reference (an example's "Make one like this"). */
    public function reference(User $user, string $conversationId, string $id, int $version): Asset
    {
        return $this->attachReference($user, $conversationId, $this->find($user, $id), $version);
    }

    /** Any other choice: the template's video is the conversation's reference, copied into the workspace once and studied. */
    private function attachReference(User $user, string $conversationId, object $t, int $version): Asset
    {
        $conversations = app(ConversationService::class);
        $kept = Asset::where('workspace_id', $user->workspace_id)->where('status', '!=', 'archived')
            ->where(fn ($q) => $q->where('metadata_json->create_template_id', $t->id)->where('asset_type', 'video')
                ->orWhere('metadata_json->create_sample_id', $t->id))->first();
        if ($kept) {
            $conversations->attach($user, $conversationId, (int) $kept->id, 'reference', $version);
            return $kept;
        }
        $dir = sys_get_temp_dir().'/create-template-'.Str::uuid();
        mkdir($dir, 0700);
        try {
            $file = $dir.'/'.$t->id.'.mp4';
            if ($t->sample_folder) {
                $r = Http::timeout(120)->sink($file)->get(self::BASE.$t->sample_folder.'/'.$t->id.'.mp4');
                abort_unless($r->successful() && is_file($file) && filesize($file) > 0, 503, 'That template could not be fetched just now. Try again shortly.');
            } else {
                $path = (string) DB::table('composition_revisions')->where('id', $t->revision_id)->value('artifact_path');
                $stream = $path ? app(CreateStorage::class)->readStream($path) : null;
                abort_unless(is_resource($stream), 503, 'That template could not be read just now. Try again shortly.');
                $out = fopen($file, 'wb');
                try { stream_copy_to_stream($stream, $out); } finally { fclose($stream); fclose($out); }
            }
            $asset = app(AttachmentUploadService::class)->upload($user, $conversationId, new UploadedFile($file, $t->id.'.mp4', 'video/mp4', null, true), 'reference', 'template:'.$conversationId.':'.$t->id, $version);
            $asset->forceFill(['title' => ($t->sample_folder ? 'Example: ' : 'Template: ').$t->title, 'metadata_json' => array_merge($asset->metadata_json ?? [], ['create_template_id' => $t->id,
                ...($t->sample_folder ? ['create_sample_id' => $t->id] : [])])])->save();
            if (config('create.mode') !== 'fixture') {
                $effort = json_decode((string) DB::table('create_conversations')->where('id', $conversationId)->value('settings_json'), true)['reference_effort'] ?? null;
                \App\Jobs\StudyCreateReference::dispatch($asset->id, References\ReferenceStudy::coverageMode($effort))->afterCommit();
            }
            return $asset->fresh();
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Ugc;

use App\Http\Controllers\Controller;
use App\Services\Ugc\UgcChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** UGC Ads as a conversation (UgcChatService). */
class UgcChatController extends Controller
{
    public function __construct(private UgcChatService $chats) {}

    /**
     * The workspace's UGC chats, newest first, a page at a time (Recent conversations, 2026-10-15): each with its title
     * (or its first message), where it stands (draft, pitched, making, made, needs a look) and the made video's
     * thumbnail. A fixed handful of queries per page, however long the history.
     */
    public function index(Request $request): JsonResponse
    {
        if (! UgcChatService::allowed($request->user())) return $this->error('not_available', 'Not available on this account yet.', 404);
        $v = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:50'], 'offset' => ['sometimes', 'integer', 'min:0'], 'search' => ['sometimes', 'nullable', 'string', 'max:120']]);
        $limit = (int) ($v['limit'] ?? 30); $offset = (int) ($v['offset'] ?? 0); $search = trim((string) ($v['search'] ?? ''));
        $first = DB::table('ugc_chat_messages as f')->select('f.content')->whereColumn('f.chat_id', 'c.id')->where('f.role', 'user')->where('f.content', '!=', '')->orderBy('f.id')->limit(1);
        $query = DB::table('ugc_chats as c')->where('c.workspace_id', $request->user()->workspace_id)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('ugc_chat_messages as m')->whereColumn('m.chat_id', 'c.id'))
            ->select('c.id', 'c.title', 'c.updated_at')->selectSub($first, 'first_text');
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(c.title) like ?', [$like])
                ->orWhereExists(fn ($m) => $m->select(DB::raw(1))->from('ugc_chat_messages as s')->whereColumn('s.chat_id', 'c.id')->where('s.role', 'user')->whereRaw('lower(s.content) like ?', [$like])));
        }
        $rows = $query->orderByDesc('c.updated_at')->orderByDesc('c.id')->offset($offset)->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        $ids = $rows->pluck('id')->all();

        // The latest pitch and the latest "making" message of each chat, in one query.
        $last = [];
        foreach (DB::table('ugc_chat_messages')->whereIn('chat_id', $ids ?: ['-'])->whereIn('kind', ['pitch', 'making'])->orderBy('id')->get(['chat_id', 'kind', 'payload_json']) as $m) {
            $last[$m->chat_id][$m->kind] = json_decode((string) $m->payload_json, true) ?: [];
        }
        $takeIds = collect($last)->flatMap(fn ($k) => collect($k['making']['runs'] ?? [])->flatMap(fn ($r) => $r['take_ids'] ?? []))->map(fn ($id) => (int) $id)->unique()->values()->all();
        $projects = $takeIds ? DB::table('projects')->whereIn('id', $takeIds)->where('workspace_id', $request->user()->workspace_id)->pluck('status', 'id') : collect();
        $visuals = $takeIds ? DB::table('scenes')->whereIn('project_id', $takeIds)->whereNotNull('visual_asset_id')->orderBy('scene_order')->get(['project_id', 'visual_asset_id'])->groupBy('project_id')->map(fn ($g) => (int) $g->first()->visual_asset_id) : collect();
        $types = $visuals->isNotEmpty() ? DB::table('assets')->whereIn('id', $visuals->values()->all())->pluck('asset_type', 'id') : collect();

        $chats = $rows->map(function ($c) use ($last, $projects, $visuals, $types) {
            $runs = $last[$c->id]['making']['runs'] ?? [];
            $statuses = collect($runs)->flatMap(fn ($r) => $r['take_ids'] ?? [])->map(fn ($id) => $projects[(int) $id] ?? null)->filter();
            $state = $runs === [] ? (isset($last[$c->id]['pitch']) ? 'pitched' : 'draft')
                : ($statuses->contains('needs_attention') ? 'needs' : ($statuses->isNotEmpty() && $statuses->every(fn ($s) => $s === 'ready_for_review') ? 'made' : 'making'));
            $take = collect($runs)->flatMap(fn ($r) => $r['take_ids'] ?? [])->first(fn ($id) => isset($visuals[(int) $id]));
            $thumb = $take !== null ? $visuals[(int) $take] : null;
            return ['id' => $c->id, 'updated_at' => $c->updated_at, 'state' => $state,
                'title' => $c->title ?: (\Illuminate\Support\Str::limit(trim((string) $c->first_text), 60) ?: 'New UGC video'),
                'thumbnail_url' => $thumb ? \Illuminate\Support\Facades\URL::temporarySignedRoute('media.assets.content', now()->addHours(2), ['assetId' => $thumb]) : null,
                'thumbnail_type' => $thumb ? ($types[$thumb] ?? null) : null];
        })->values();
        return response()->json(['data' => ['chats' => $chats], 'meta' => ['next_offset' => $more ? $offset + $limit : null]]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($gate = $this->gate($request)) return $gate;
        $id = $this->chats->create($request->user());
        return response()->json(['data' => $this->chats->show($request, $id), 'meta' => []], 201);
    }

    /** The places a presenter can be filmed in, each with its background photo (only those whose photo exists). */
    public function settings(Request $request): JsonResponse
    {
        if (! UgcChatService::allowed($request->user())) return $this->error('not_available', 'Not available on this account yet.', 404);
        return response()->json(['data' => ['settings' => \App\Services\Ugc\UgcSettings::listed(), 'types' => \App\Services\Ugc\UgcTypes::menu($request->user())], 'meta' => []]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! UgcChatService::allowed($request->user())) return $this->error('not_available', 'Not available on this account yet.', 404);
        return response()->json(['data' => $this->chats->show($request, $id), 'meta' => []]);
    }

    public function message(Request $request, string $id): JsonResponse
    {
        if ($gate = $this->gate($request)) return $gate;
        $v = $request->validate([
            'text' => ['nullable', 'string', 'max:4000'],
            'attachments' => ['sometimes', 'array', 'max:20'],
            'attachments.*.asset_id' => ['required', 'integer'],
            'attachments.*.role' => ['nullable', 'string', \Illuminate\Validation\Rule::in(\App\Services\Ugc\UgcTypes::ROLES)],
            'link' => ['nullable', 'url', 'max:2000'],
            'presenters' => ['sometimes', 'array', 'max:5'],
            'presenters.*.character_id' => ['nullable', 'integer'],
            'presenters.*.voice' => ['nullable', 'array'],
            // Where this presenter is filmed (config/ugc_settings.php); empty lets the director pick.
            'presenters.*.setting' => ['nullable', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Services\Ugc\UgcSettings::all()))],
            'presenters.*.voice.pace' => ['nullable', 'string', 'in:slower,natural,faster'],
            'presenters.*.voice.delivery' => ['nullable', 'string', 'in:friendly,excited,calm,deadpan'],
            'presenters.*.voice.direction' => ['nullable', 'string', 'max:300'],
            'type' => ['sometimes', 'string', 'in:auto,'.implode(',', array_diff(UgcChatService::TYPES, \App\Services\Ugc\UgcTypes::unavailable($request->user())))],
            'aspect' => ['sometimes', 'string', 'in:9:16,1:1,16:9'],
            'length' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:60'],
        ]);
        if (trim((string) ($v['text'] ?? '')) === '' && empty($v['attachments']) && empty($v['link'])) {
            return $this->error('empty_message', 'Say what you want, or add your product.', 422);
        }
        $reply = $this->chats->message($request, $id, $v);
        return response()->json(['data' => ['reply' => $reply, 'chat' => $this->chats->show($request, $id)], 'meta' => []]);
    }

    /** Take a file out of what the chat uses, such as a photo brought from their product page. */
    public function removeAttachment(Request $request, string $id): JsonResponse
    {
        if (! UgcChatService::allowed($request->user())) return $this->error('not_available', 'Not available on this account yet.', 404);
        $v = $request->validate(['asset_id' => ['required', 'integer']]);
        $this->chats->removeAttachment($request->user(), $id, (int) $v['asset_id']);
        return response()->json(['data' => ['chat' => $this->chats->show($request, $id)], 'meta' => []]);
    }

    /** "Make it": spends credits, so the user confirms the rights the wizard asked for. */
    public function make(Request $request, string $id): JsonResponse
    {
        if ($gate = $this->gate($request)) return $gate;
        $v = $request->validate(['message_id' => ['required', 'integer'], 'request_id' => ['required', 'uuid'], 'consent' => ['accepted'],
            'engine' => ['sometimes', 'nullable', 'string', 'in:auto,omni,seedance25,veo'],
            'placement' => ['sometimes', 'string', 'in:'.implode(',', \App\Services\Ugc\UgcComposition::PLACEMENTS)],
            'size' => ['sometimes', 'string', 'in:'.implode(',', \App\Services\Ugc\UgcComposition::SIZES)],
            // A music bed under a POV hook or a reaction (off unless asked, owner 2026-10-15).
            'music' => ['sometimes', 'boolean']]);
        $reply = $this->chats->make($request, $id, (int) $v['message_id'], (string) $v['request_id'], ($v['engine'] ?? 'auto') === 'auto' ? null : $v['engine'],
            array_intersect_key($v, ['placement' => 1, 'size' => 1, 'music' => 1]));
        return response()->json(['data' => ['reply' => $reply, 'chat' => $this->chats->show($request, $id)], 'meta' => []]);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        if ($gate = $this->gate($request)) return $gate;
        $v = $request->validate(['message_id' => ['required', 'integer'], 'run' => ['required', 'integer', 'min:0'], 'engine' => ['sometimes', 'string', 'in:seedance25,omni,veo,veo_hq']]);
        return response()->json(['data' => ['chat' => $this->chats->retry($request, $id, (int) $v['message_id'], (int) $v['run'], $v['engine'] ?? null)], 'meta' => []]);
    }

    public function rewrite(Request $request, string $id): JsonResponse
    {
        if ($gate = $this->gate($request)) return $gate;
        $v = $request->validate(['script' => ['required', 'string', 'min:10', 'max:4000']]);
        return response()->json(['data' => ['script' => $this->chats->rewrite($request, $id, $v['script'])], 'meta' => []]);
    }

    /** The same plan gate as the UGC wizard: UGC is a paid-plan feature. While staff-only, others get the wizard. */
    private function gate(Request $request): ?JsonResponse
    {
        if (! UgcChatService::allowed($request->user())) return $this->error('not_available', 'Not available on this account yet.', 404);
        return app(\App\Services\CreditService::class)->limitFor((int) $request->user()->workspace_id, 'ugc_ads') ? null
            : $this->error('upgrade_required', 'UGC ads are available on paid plans. Upgrade to start making them.', 402);
    }
}

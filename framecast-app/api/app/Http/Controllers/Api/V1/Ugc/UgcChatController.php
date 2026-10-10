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

    public function index(Request $request): JsonResponse
    {
        // A chat with no pitch yet is named by its first message, so unfinished chats can be told apart.
        $rows = DB::table('ugc_chats')->where('workspace_id', $request->user()->workspace_id)->orderByDesc('updated_at')->limit(30)
            ->get(['id', 'title', 'updated_at'])->map(fn ($c) => ['id' => $c->id, 'updated_at' => $c->updated_at, 'title' => $c->title
                ?: \Illuminate\Support\Str::limit(trim((string) DB::table('ugc_chat_messages')->where('chat_id', $c->id)->where('role', 'user')->where('content', '!=', '')->orderBy('id')->value('content')), 48)
                ?: 'New UGC video'])
            ->filter(fn ($c) => DB::table('ugc_chat_messages')->where('chat_id', $c['id'])->exists())->values();
        return response()->json(['data' => ['chats' => $rows], 'meta' => []]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($gate = $this->gate($request)) return $gate;
        $id = $this->chats->create($request->user());
        return response()->json(['data' => $this->chats->show($request, $id), 'meta' => []], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->chats->show($request, $id), 'meta' => []]);
    }

    public function message(Request $request, string $id): JsonResponse
    {
        if ($gate = $this->gate($request)) return $gate;
        $v = $request->validate([
            'text' => ['nullable', 'string', 'max:4000'],
            'attachments' => ['sometimes', 'array', 'max:20'],
            'attachments.*.asset_id' => ['required', 'integer'],
            'attachments.*.role' => ['nullable', 'string', 'in:product,footage,sample'],
            'link' => ['nullable', 'url', 'max:2000'],
            'presenters' => ['sometimes', 'array', 'max:5'],
            'presenters.*.character_id' => ['nullable', 'integer'],
            'presenters.*.voice' => ['nullable', 'array'],
            'presenters.*.voice.pace' => ['nullable', 'string', 'in:slower,natural,faster'],
            'presenters.*.voice.delivery' => ['nullable', 'string', 'in:friendly,excited,calm,deadpan'],
            'presenters.*.voice.direction' => ['nullable', 'string', 'max:300'],
            'type' => ['sometimes', 'string', 'in:auto,'.implode(',', UgcChatService::TYPES)],
            'aspect' => ['sometimes', 'string', 'in:9:16,1:1,16:9'],
            'length' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:60'],
        ]);
        if (trim((string) ($v['text'] ?? '')) === '' && empty($v['attachments']) && empty($v['link'])) {
            return $this->error('empty_message', 'Say what you want, or add your product.', 422);
        }
        $reply = $this->chats->message($request, $id, $v);
        return response()->json(['data' => ['reply' => $reply, 'chat' => $this->chats->show($request, $id)], 'meta' => []]);
    }

    /** "Make it": spends credits, so the user confirms the rights the wizard asked for. */
    public function make(Request $request, string $id): JsonResponse
    {
        if ($gate = $this->gate($request)) return $gate;
        $v = $request->validate(['message_id' => ['required', 'integer'], 'request_id' => ['required', 'uuid'], 'consent' => ['accepted']]);
        $reply = $this->chats->make($request, $id, (int) $v['message_id'], (string) $v['request_id']);
        return response()->json(['data' => ['reply' => $reply, 'chat' => $this->chats->show($request, $id)], 'meta' => []]);
    }

    public function rewrite(Request $request, string $id): JsonResponse
    {
        if ($gate = $this->gate($request)) return $gate;
        $v = $request->validate(['script' => ['required', 'string', 'min:10', 'max:4000']]);
        return response()->json(['data' => ['script' => $this->chats->rewrite($request, $id, $v['script'])], 'meta' => []]);
    }

    /** The same plan gate as the UGC wizard: UGC is a paid-plan feature. */
    private function gate(Request $request): ?JsonResponse
    {
        return app(\App\Services\CreditService::class)->limitFor((int) $request->user()->workspace_id, 'ugc_ads') ? null
            : $this->error('upgrade_required', 'UGC ads are available on paid plans. Upgrade to start making them.', 402);
    }
}

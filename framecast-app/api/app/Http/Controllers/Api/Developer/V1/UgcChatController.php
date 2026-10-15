<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Http\Controllers\Api\V1\Ugc\UgcChatController as AppUgcChatController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * The UGC Creative Director through the developer API and MCP (MCP-BRIEF, owner 2026-10-15: "let it jump, UGC is
 * standalone now"): a brief in, a question or a pitch with its price out, replies until the pitch is right, then make.
 * Every step is the app's own chat (App\Http\Controllers\Api\V1\Ugc\UgcChatController) with an internal request, so
 * the director, the completeness check, pronunciations, the type rules, the credit hold and the gates are the app's.
 * This class shapes the input and trims the answers for an assistant to read.
 */
class UgcChatController extends DeveloperController
{
    /** Free: start a chat with the brief; the director answers with one question or a pitch. */
    public function start(Request $request): JsonResponse
    {
        $input = $this->validated($request, $this->messageRules($request) + ['text' => ['required', 'string', 'max:4000']]);
        $created = $this->delegate($request, fn (AppUgcChatController $c, Request $r) => $c->store($r), []);
        if ($created instanceof JsonResponse) return $created;

        return $this->send($request, (string) $created['id'], $input);
    }

    /** Free: answer the director's question, or ask for changes to the pitch. */
    public function reply(Request $request, string $id): JsonResponse
    {
        $input = $this->validated($request, $this->messageRules($request) + ['text' => ['required', 'string', 'max:4000']]);

        return $this->send($request, $id, $input);
    }

    /** Paid: make the pitched video. The credits are held as it starts and given back if it does not deliver. */
    public function make(Request $request, string $id): JsonResponse
    {
        $input = $this->validated($request, [
            'message_id' => ['required', 'integer'], 'consent' => ['accepted'],
            'engine' => ['sometimes', 'nullable', 'string', 'in:auto,omni,seedance25,veo'],
            'music' => ['sometimes', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);
        $key = (string) ($input['idempotency_key'] ?? $request->header('Idempotency-Key') ?? ('pitch-'.$input['message_id']));
        // The app wants a UUID per request; the same key always maps to the same one, so a retried call makes one video.
        $requestId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'wyv-ugc-chat:'.$request->user()->workspace_id.':'.$id.':'.$key)->toString();
        $made = $this->delegate($request, fn (AppUgcChatController $c, Request $r) => $c->make($r, $id), [
            'message_id' => (int) $input['message_id'], 'request_id' => $requestId, 'consent' => true,
            'engine' => $input['engine'] ?? 'auto', 'music' => (bool) ($input['music'] ?? false),
        ]);
        if ($made instanceof JsonResponse) return $made;

        return response()->json(['data' => ['chat_id' => $id, 'reply' => self::readable($made['reply'] ?? []), 'videos' => self::videos($made['chat'] ?? [])], 'meta' => []], 201);
    }

    /** Free: where the chat stands, with each video's status and, once ready, its link. */
    public function show(Request $request, string $id): JsonResponse
    {
        $chat = $this->delegate($request, fn (AppUgcChatController $c, Request $r) => $c->show($r, $id), []);
        if ($chat instanceof JsonResponse) return $chat;
        $last = collect($chat['messages'] ?? [])->where('role', 'assistant')->last();

        return response()->json(['data' => ['chat_id' => $id, 'title' => $chat['title'] ?? null, 'last_reply' => $last ? self::readable($last) : null, 'videos' => self::videos($chat)], 'meta' => []]);
    }

    private function send(Request $request, string $id, array $input): JsonResponse
    {
        $sent = $this->delegate($request, fn (AppUgcChatController $c, Request $r) => $c->message($r, $id), array_filter([
            'text' => $input['text'], 'type' => $input['type'] ?? null, 'aspect' => $input['aspect'] ?? null, 'length' => $input['length'] ?? null,
            'link' => $input['link'] ?? null, 'attachments' => $input['attachments'] ?? null,
            'presenters' => isset($input['character_ids']) ? array_map(fn ($cid) => ['character_id' => (int) $cid], $input['character_ids']) : null,
        ], fn ($v) => $v !== null));
        if ($sent instanceof JsonResponse) return $sent;

        return response()->json(['data' => ['chat_id' => $id, 'reply' => self::readable($sent['reply'] ?? [])], 'meta' => []]);
    }

    private function messageRules(Request $request): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::in(['auto', ...\App\Services\Ugc\UgcChatService::TYPES])],
            'aspect' => ['sometimes', 'string', 'in:9:16,1:1,16:9'],
            'length' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:60'],
            'link' => ['nullable', 'url', 'max:2000'],
            'attachments' => ['sometimes', 'array', 'max:20'],
            'attachments.*.asset_id' => ['required', 'integer'],
            'attachments.*.role' => ['nullable', 'string', Rule::in(\App\Services\Ugc\UgcTypes::ROLES)],
            'character_ids' => ['sometimes', 'array', 'max:5'],
            'character_ids.*' => ['integer'],
        ];
    }

    /** A director's message as an assistant reads it: what it asked or pitched, and what making it costs. */
    private static function readable(array $m): array
    {
        $p = $m['payload'] ?? [];
        $out = ['message_id' => $m['id'] ?? null, 'kind' => $m['kind'] ?? null, 'text' => $m['content'] ?? ''];
        if (! empty($p['chips'])) $out['suggested_answers'] = $p['chips'];
        if (($m['kind'] ?? '') === 'pitch') {
            $out['pitch'] = [
                'type' => $p['type'] ?? null, 'title' => $p['title'] ?? null, 'aspect' => $p['aspect'] ?? null, 'credits' => $p['credits'] ?? null,
                'script' => $p['plan']['script'] ?? null, 'shots' => count($p['plan']['segments'] ?? []),
                'videos' => array_map(fn ($t) => array_filter(['presenter' => $t['name'] ?? null, 'model' => $t['engine'] ?? null, 'credits' => $t['credits'] ?? null,
                    'other_models' => array_values(array_map(fn ($e) => array_filter(['model' => $e['key'], 'credits' => $e['credits'], 'available' => $e['available'], 'note' => $e['note'] ?: null], fn ($v) => $v !== null),
                        $t['engines'] ?? []))], fn ($v) => $v !== null && $v !== []), $p['takes'] ?? []),
                'music_bed_available' => in_array($p['type'] ?? '', ['pov_hook', 'reaction'], true),
            ];
        }
        return $out;
    }

    /** Every video the chat has started: its status and, when ready, a link to watch it. */
    private static function videos(array $chat): array
    {
        $out = [];
        foreach ($chat['messages'] ?? [] as $m) {
            foreach ($m['payload']['runs'] ?? [] as $run) {
                foreach ($run['takes'] ?? [] as $t) {
                    $out[] = array_filter(['video_id' => $t['id'] ?? null, 'presenter' => $run['name'] ?? null, 'status' => $t['status'] ?? null,
                        'url' => ($t['status'] ?? '') === 'ready' ? ($t['thumbnail_url'] ?? null) : null, 'aspect' => $t['aspect'] ?? null], fn ($v) => $v !== null);
                }
            }
        }
        return $out;
    }

    private function delegate(Request $outer, callable $action, array $payload): array|JsonResponse
    {
        $inner = Request::create('/internal/ugc-chats', 'POST', $payload);
        $inner->headers->set('Accept', 'application/json');
        $inner->setUserResolver(fn () => $outer->user());
        $inner->attributes->set('api_key_id', $outer->attributes->get('api_key_id'));
        try {
            $response = $action(app(AppUgcChatController::class), $inner);
        } catch (ValidationException $e) {
            return $this->fail('validation_failed', collect($e->errors())->flatten()->first() ?? 'Invalid request.', 422, ['errors' => $e->errors()]);
        }
        $status = $response->getStatusCode();
        $body = $response->getData(true);
        if ($status >= 400) {
            $err = $body['error'] ?? ['code' => 'refused', 'message' => 'The request was refused.'];

            return $this->fail((string) ($err['code'] ?? 'refused'), (string) ($err['message'] ?? ''), $status, array_filter(['context' => $err['context'] ?? null]));
        }

        return $body['data'] ?? [];
    }
}

<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;

/**
 * The build agent's Claude API calls go through the app, not the worker. The
 * app checks the call matches the attempt the worker recorded, makes it, reads
 * the token usage itself and settles the charge. The worker never holds the
 * key, and the usage behind a charge is what the provider returned to us.
 */
class AnthropicGateway
{
    /** Tool-mode history must be a bounded, well-formed conversation: known roles and block types, few images, modest size. */
    public static function checkToolMessages(mixed $messages, mixed $tools): void
    {
        $unlimited = PilotPolicy::unlimited();
        abort_unless(is_array($messages) && count($messages) >= 1 && count($messages) <= ($unlimited ? 2000 : 120), 422, 'Invalid tool conversation.');
        abort_unless(is_array($tools) && count($tools) <= ($unlimited ? 64 : 24), 422, 'Invalid tool list.');
        foreach ($tools as $t) abort_unless(is_array($t) && preg_match('/^[a-z_]{2,40}$/', (string) ($t['name'] ?? '')) && is_array($t['input_schema'] ?? null), 422, 'Invalid tool definition.');
        $images = 0;
        foreach ($messages as $m) {
            abort_unless(is_array($m) && in_array($m['role'] ?? '', ['user', 'assistant'], true) && is_array($m['content'] ?? null), 422, 'Invalid message.');
            foreach ($m['content'] as $b) {
                $type = is_array($b) ? ($b['type'] ?? '') : '';
                abort_unless(in_array($type, ['text', 'image', 'tool_use', 'tool_result'], true), 422, 'Invalid message block.');
                $inner = $type === 'tool_result' && is_array($b['content'] ?? null) ? $b['content'] : [];
                foreach ([$b, ...$inner] as $x) {
                    if (($x['type'] ?? '') !== 'image') continue;
                    $src = $x['source'] ?? [];
                    abort_unless(($src['type'] ?? '') === 'base64' && in_array($src['media_type'] ?? '', ['image/png', 'image/jpeg'], true) && is_string($src['data'] ?? null) && strlen($src['data']) <= ($unlimited ? 6_800_000 : 1_400_000), 422, 'Only inline PNG or JPEG images are accepted.');
                    abort_if(++$images > ($unlimited ? 20 : 4), 422, 'Too many images in one request.');
                }
            }
        }
    }

    public function complete(string $runId, string $lease, string $attemptId, array $input): array
    {
        app(RunService::class)->validateResultLease($runId, $lease);
        $attempt = DB::table('composition_attempts')->where('run_id', $runId)->where('id', $attemptId)->firstOrFail();
        abort_unless($attempt->provider === 'anthropic' && in_array($attempt->kind, ['agent', 'critic'], true), 422, 'This attempt is not an Anthropic agent call.');
        $canonical = ['prompt' => $input['prompt'], 'system' => $input['system'], 'maxTokens' => $input['max_tokens'], 'image' => $input['image'] ?? null];
        // Tool mode: the worker hashes the serialised history and tool list as strings, so both sides agree byte for byte.
        $toolMode = is_string($input['messages_json'] ?? null) && $input['messages_json'] !== '';
        if ($toolMode) { $canonical['messagesJson'] = $input['messages_json']; $canonical['toolsJson'] = (string) ($input['tools_json'] ?? ''); }
        $hash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
        abort_unless(hash_equals($attempt->request_hash, $hash), 409, 'The call does not match the recorded attempt.');

        // Output tokens are bounded by what was approved with the run.
        $policy = data_get(json_decode((string) DB::table('composition_runs')->where('id', $runId)->value('input_json'), true), 'execution_policy.'.$attempt->kind, []);
        abort_unless((int) $input['max_tokens'] <= (int) ($policy['max_output_tokens'] ?? 8192), 422, 'The output limit is above what this run approved.');

        $content = [];
        if (! empty($input['image'])) {
            abort_unless(preg_match('~^data:(image/(?:png|jpeg));base64,([A-Za-z0-9+/=]+)$~', $input['image'], $m) && strlen($m[2]) <= (PilotPolicy::unlimited() ? 6_800_000 : 1_400_000), 422, 'Only an inline review image is accepted.');
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $m[1], 'data' => $m[2]]];
        }
        $content[] = ['type' => 'text', 'text' => $input['prompt']];
        $messages = [['role' => 'user', 'content' => $content]];
        $tools = null;
        if ($toolMode) {
            $messages = json_decode($input['messages_json'], true, 16);
            $tools = json_decode((string) ($input['tools_json'] ?? '[]'), true, 16);
            self::checkToolMessages($messages, $tools);
            // The body keeps the worker's objects: an empty {} (a tool with no fields, a tool_use with no input)
            // must not become [] on re-encoding, which the provider rejects.
            $messages = json_decode($input['messages_json'], false, 16);
            $tools = json_decode((string) ($input['tools_json'] ?? '[]'), false, 16);
        }
        // Opus can take over two minutes to write a full composition with its thinking.
        set_time_limit(PilotPolicy::unlimited() ? 960 : 320);
        $attempts = app(AttemptService::class);
        $body = ['model' => $attempt->model, 'max_tokens' => (int) $input['max_tokens'],
            // The effort approved with the run, so a later settings change never alters a build in flight.
            'output_config' => ['effort' => (string) (data_get($policy, 'effort') ?: config('create.agent_effort', 'medium'))],
            'system' => [['type' => 'text', 'text' => $input['system'], 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => $messages, ...($tools ? ['tools' => $tools] : [])];
        // A failure to connect means nothing was sent, so it is safe to try again.
        $journal = app(DispatchJournal::class);
        $saved = $journal->claim($attemptId);
        $response = $saved ? new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response($saved['status'], $saved['headers'], $saved['body'])) : null;
        $notSent = false;
        for ($try = 1; $try <= 3 && ! $response; $try++) {
            try {
                $response = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])
                    ->acceptJson()->connectTimeout(10)->timeout(PilotPolicy::unlimited() ? 900 : 280)->post('https://api.anthropic.com/v1/messages', $body);
                // Overloaded or rate-limited: refused and not billed, so a short wait and another try is safe.
                if (in_array($response->status(), [429, 529], true) && $try < 3) {
                    \Illuminate\Support\Facades\Log::warning('Create gateway call refused, retrying', ['run' => $runId, 'attempt' => $attemptId, 'try' => $try, 'status' => $response->status()]);
                    $response = null; $notSent = true; sleep(4 * $try);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Create gateway call did not complete', ['run' => $runId, 'attempt' => $attemptId, 'try' => $try, 'error' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 300)]);
                $notSent = (bool) preg_match('/Failed to connect|Could not resolve|Connection refused|Couldn.t connect|Resolving timed out/i', $e->getMessage());
                if (! $notSent) break;
                if ($try < 3) usleep(1_500_000 * $try);
            }
        }
        if (! $response && $notSent) {
            // Never reached Anthropic: record it as not sent, charge nothing.
            $id = 'not-sent-'.Str::uuid();
            $attempts->bindPrediction($runId, $lease, $attemptId, $id);
            $receipt = new VerifiedAttemptReceipt($attemptId, 'failed', $id, 0, 'pilot-tariff:2026-09-30; anthropic could not be reached, request never sent');
            $attempts->settle($runId, $lease, $attemptId, $receipt->result(), $receipt);
            abort(503, 'Anthropic could not be reached; nothing was sent or charged. Try again shortly.');
        }
        if (! $response) {
            // Sent or not is unknown: keep the hold and let reconciliation decide.
            $attempts->settle($runId, $lease, $attemptId, ['status' => 'unknown']);
            abort(502, 'The model call did not complete. The run needs a recovery check; nothing is repeated automatically.');
        }
        $journal->save($attemptId, ['status' => $response->status(), 'headers' => ['request-id' => $response->header('request-id')], 'body' => $response->body(), 'rates' => config('create.anthropic_rates')]);
        if (! $response->successful()) {
            if ($response->status() >= 500 && $response->status() !== 529) {
                $attempts->settle($runId, $lease, $attemptId, ['status' => 'unknown']);
                abort(502, 'Provider outcome is uncertain; the saved response needs reconciliation.');
            }
            // Refused requests are not billed. The request id is the receipt; without one, reconcile.
            $requestId = (string) $response->header('request-id');
            if (! preg_match('/^[a-zA-Z0-9_-]{1,160}$/D', $requestId)) {
                $attempts->settle($runId, $lease, $attemptId, ['status' => 'unknown']);
                abort(502, 'The model refused this call without a request id; held for review.');
            }
            $attempts->bindPrediction($runId, $lease, $attemptId, $requestId);
            $receipt = new VerifiedAttemptReceipt($attemptId, 'failed', $requestId, 0, 'pilot-tariff:2026-09-30; anthropic refused the request ('.$response->status().')');
            $attempts->settle($runId, $lease, $attemptId, $receipt->result(), $receipt);
            \Illuminate\Support\Facades\Log::warning('Create gateway call refused', ['run' => $runId, 'attempt' => $attemptId, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);
            abort(503, 'The model refused this call ('.$response->status().'); nothing was charged.');
        }
        $id = (string) $response->json('id');
        abort_unless(preg_match('/^[a-zA-Z0-9_-]{1,160}$/D', $id), 502, 'The model response had no usable id.');
        $u = $response->json('usage', []);
        [$in, $out, $write, $read] = array_map(fn ($k) => (int) ($u[$k] ?? 0), ['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens']);
        $r = $saved['rates'] ?? config('create.anthropic_rates');
        $cost = (int) ceil($in * $r['input'] + $out * $r['output'] + $write * $r['cache_write'] + $read * $r['cache_read']);
        if ($attempt->status === 'started') $attempts->bindPrediction($runId, $lease, $attemptId, $id);
        if ($cost > (int) $attempt->cost_limit_microusd) {
            $attempts->settle($runId, $lease, $attemptId, ['status' => 'unknown']);
            abort(409, 'The call cost more than its approved ceiling; held for review.');
        }
        $receipt = new VerifiedAttemptReceipt($attemptId, 'succeeded', $id, $cost,
            'pilot-tariff:2026-09-30; anthropic usage returned to WyvStudio: in '.$in.', out '.$out.', cache write '.$write.', cache read '.$read);
        $settled = $attempts->settle($runId, $lease, $attemptId, $receipt->result(), $receipt);
        // A tool call with no fields decodes as an empty array; it must go back out as {} or the next call is refused.
        $blocks = collect($response->json('content', []))->filter(fn ($b) => is_array($b) && in_array($b['type'] ?? '', ['text', 'tool_use'], true))
            ->map(fn ($b) => $b['type'] === 'tool_use' && is_array($b['input'] ?? null) && $b['input'] === [] ? [...$b, 'input' => new \stdClass] : $b)->values()->all();
        $text = collect($blocks)->where('type', 'text')->pluck('text')->implode('');
        return ['text' => $text, 'content' => $blocks, 'message_id' => $id, 'stop_reason' => (string) $response->json('stop_reason'), 'cost_microusd' => $cost, 'charged_credits' => $settled['charged_credits'],
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out, 'cache_write_tokens' => $write, 'cache_read_tokens' => $read], 'status' => 'succeeded'];
    }
}

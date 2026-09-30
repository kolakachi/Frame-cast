<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\{DB, Http};

/**
 * The build agent's Claude API calls go through the app, not the worker. The
 * app checks the call matches the attempt the worker recorded, makes it, reads
 * the token usage itself and settles the charge. The worker never holds the
 * key, and the usage behind a charge is what the provider returned to us.
 */
class AnthropicGateway
{
    public function complete(string $runId, string $lease, string $attemptId, array $input): array
    {
        app(RunService::class)->validateResultLease($runId, $lease);
        $attempt = DB::table('composition_attempts')->where('run_id', $runId)->where('id', $attemptId)->firstOrFail();
        abort_unless($attempt->provider === 'anthropic' && $attempt->kind === 'agent', 422, 'This attempt is not an Anthropic agent call.');
        abort_unless($attempt->status === 'started' && ! $attempt->prediction_id, 409, 'This attempt was already sent. Reconcile instead of sending again.');
        $canonical = ['prompt' => $input['prompt'], 'system' => $input['system'], 'maxTokens' => $input['max_tokens'], 'image' => $input['image'] ?? null];
        $hash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
        abort_unless(hash_equals($attempt->request_hash, $hash), 409, 'The call does not match the recorded attempt.');

        $content = [];
        if (! empty($input['image'])) {
            abort_unless(preg_match('~^data:(image/(?:png|jpeg));base64,([A-Za-z0-9+/=]+)$~', $input['image'], $m) && strlen($m[2]) <= 1_400_000, 422, 'Only an inline review image is accepted.');
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $m[1], 'data' => $m[2]]];
        }
        $content[] = ['type' => 'text', 'text' => $input['prompt']];
        // Opus can take over two minutes to write a full composition with its thinking.
        set_time_limit(320);
        $attempts = app(AttemptService::class);
        try {
            $response = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])
                ->acceptJson()->timeout(280)->post('https://api.anthropic.com/v1/messages', [
                    'model' => $attempt->model, 'max_tokens' => (int) $input['max_tokens'],
                    'output_config' => ['effort' => (string) config('create.agent_effort', 'medium')],
                    'system' => [['type' => 'text', 'text' => $input['system'], 'cache_control' => ['type' => 'ephemeral']]],
                    'messages' => [['role' => 'user', 'content' => $content]],
                ]);
        } catch (\Throwable $e) {
            // Sent or not is unknown: keep the hold and let reconciliation decide.
            \Illuminate\Support\Facades\Log::warning('Create gateway call did not complete', ['run' => $runId, 'attempt' => $attemptId, 'error' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 300)]);
            $attempts->settle($runId, $lease, $attemptId, ['status' => 'unknown']);
            abort(502, 'The model call did not complete. The run needs a recovery check; nothing is repeated automatically.');
        }
        if (! $response->successful()) {
            // Refused requests are not billed. The request id is the receipt; without one, reconcile.
            $requestId = (string) $response->header('request-id');
            if (! preg_match('/^[a-zA-Z0-9_-]{1,160}$/D', $requestId)) {
                $attempts->settle($runId, $lease, $attemptId, ['status' => 'unknown']);
                abort(502, 'The model refused this call without a request id; held for review.');
            }
            $attempts->bindPrediction($runId, $lease, $attemptId, $requestId);
            $receipt = new VerifiedAttemptReceipt($attemptId, 'failed', $requestId, 0, 'pilot-tariff:2026-09-30; anthropic refused the request ('.$response->status().')');
            $attempts->settle($runId, $lease, $attemptId, $receipt->result(), $receipt);
            abort(502, 'The model refused this call ('.$response->status().').');
        }
        $id = (string) $response->json('id');
        abort_unless(preg_match('/^[a-zA-Z0-9_-]{1,160}$/D', $id), 502, 'The model response had no usable id.');
        $u = $response->json('usage', []);
        [$in, $out, $write, $read] = array_map(fn ($k) => (int) ($u[$k] ?? 0), ['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens']);
        $r = config('create.anthropic_rates');
        $cost = (int) ceil($in * $r['input'] + $out * $r['output'] + $write * $r['cache_write'] + $read * $r['cache_read']);
        $attempts->bindPrediction($runId, $lease, $attemptId, $id);
        if ($cost > (int) $attempt->cost_limit_microusd) {
            $attempts->settle($runId, $lease, $attemptId, ['status' => 'unknown']);
            abort(409, 'The call cost more than its approved ceiling; held for review.');
        }
        $receipt = new VerifiedAttemptReceipt($attemptId, 'succeeded', $id, $cost,
            'pilot-tariff:2026-09-30; anthropic usage returned to WyvStudio: in '.$in.', out '.$out.', cache write '.$write.', cache read '.$read);
        $settled = $attempts->settle($runId, $lease, $attemptId, $receipt->result(), $receipt);
        $text = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        return ['text' => $text, 'message_id' => $id, 'stop_reason' => (string) $response->json('stop_reason'), 'cost_microusd' => $cost, 'charged_credits' => $settled['charged_credits'],
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out, 'cache_write_tokens' => $write, 'cache_read_tokens' => $read], 'status' => 'succeeded'];
    }
}

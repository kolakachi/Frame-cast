<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\{DB, Http, Storage};

/** Fixed operations from the approved run; never a general provider proxy. */
class ReplicateGateway
{
    private const MODELS = ['anthropic/claude-4.5-sonnet', 'google/nano-banana', 'wan-video/wan-2.5-i2v'];

    private function run(string $id, string $lease): array
    {
        app(RunService::class)->validateResultLease($id, $lease);
        $run = DB::table('composition_runs')->where('id', $id)->firstOrFail();
        abort_unless($run->status === 'running' && PilotPolicy::enabled(), 409, 'Only a current approved run can call the provider.');
        return json_decode($run->input_json, true);
    }

    private function http()
    {
        return Http::withToken((string) config('services.replicate.api_token'))->acceptJson()->connectTimeout(10)->timeout(90)->withOptions(['allow_redirects' => false]);
    }

    public function uploadImage(string $id, string $lease, string $data): array
    {
        $input = $this->run($id, $lease);
        abort_unless(($input['execution_policy']['agent']['provider'] ?? '') === 'replicate', 422);
        abort_unless(preg_match('~^data:(image/(?:png|jpeg|webp));base64,([A-Za-z0-9+/=]+)$~', $data, $m) && strlen($m[2]) <= 1_400_000, 422);
        $url = $this->upload(base64_decode($m[2], true), $m[1]);
        // Only uploaded review images belonging to this run may be used in its calls.
        DB::transaction(function () use ($id, $url) {
            $run = DB::table('composition_runs')->where('id', $id)->lockForUpdate()->firstOrFail();
            $input = json_decode($run->input_json, true);
            $input['gateway_review_images'] = array_slice(array_values(array_unique([...($input['gateway_review_images'] ?? []), $url])), -30);
            DB::table('composition_runs')->where('id', $id)->update(['input_json' => json_encode($input)]);
        });
        return ['url' => $url];
    }

    private function upload(string $bytes, string $mime): string
    {
        abort_unless($bytes !== '' && strlen($bytes) <= 10 * 1024 * 1024 && in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 422);
        $r = $this->http()->attach('content', $bytes, hash('sha256', $bytes).'.'.(['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime]), ['Content-Type' => $mime])
            ->post('https://api.replicate.com/v1/files')->throw();
        $url = (string) $r->json('urls.get');
        abort_unless(preg_match('~^https://api\.replicate\.com/v1/files/[A-Za-z0-9_.-]+$~D', $url), 502, 'Invalid provider file receipt.');
        return $url;
    }

    public function prepareMedia(string $id, string $lease): array
    {
        $input = $this->run($id, $lease);
        $model = $input['execution_policy']['media']['model'] ?? '';
        abort_unless(in_array($model, ['google/nano-banana', 'wan-video/wan-2.5-i2v'], true), 422);
        if (isset($input['gateway_media_input'])) return $input['gateway_media_input'];
        $files = array_values(array_filter($input['input_files'] ?? [], fn ($f) => $f['purpose'] === 'source'));
        abort_unless(count($files) <= 4 && ($model !== 'wan-video/wan-2.5-i2v' || count($files) === 1), 422);
        app(InputSnapshotService::class)->verify($files);
        $urls = [];
        foreach ($files as $f) {
            abort_unless($f['asset_type'] === 'image' && $f['bytes'] <= 10 * 1024 * 1024, 422);
            $urls[] = $this->upload(app(\App\Services\Create\CreateStorage::class)->get($f['storage_path']), $f['mime_type']);
        }
        $body = $input['media_input'];
        if ($model === 'wan-video/wan-2.5-i2v') $body['image'] = $urls[0];
        elseif ($urls) $body['image_input'] = $urls;
        return DB::transaction(function () use ($id, $body) {
            $run = DB::table('composition_runs')->where('id', $id)->lockForUpdate()->firstOrFail();
            $input = json_decode($run->input_json, true);
            if (isset($input['gateway_media_input'])) return $input['gateway_media_input'];
            $input['gateway_media_input'] = $body;
            DB::table('composition_runs')->where('id', $id)->update(['input_json' => json_encode($input)]);
            return $body;
        });
    }

    public function prediction(string $id, string $lease, string $attemptId, array $body, bool $poll = false): array
    {
        $input = $this->run($id, $lease);
        $a = DB::table('composition_attempts')->where('run_id', $id)->where('id', $attemptId)->firstOrFail();
        abort_unless($a->provider === 'replicate' && in_array($a->model, self::MODELS, true), 422, 'This model is not an approved app tool.');
        $canonical = $body;
        if ($a->kind === 'agent') {
            abort_unless($a->model === self::MODELS[0], 422);
            validator($body, ['prompt' => 'required|string|max:200000', 'system' => 'required|string|max:200000', 'maxTokens' => 'required|integer|min:256|max:4096', 'image' => 'nullable|string|max:1500000'])->validate();
            abort_unless($body['maxTokens'] <= ($input['execution_policy']['agent']['max_output_tokens'] ?? 4096), 422, 'Output tokens exceed the approved limit.');
            abort_unless(array_diff(array_keys($body), ['prompt', 'system', 'maxTokens', 'image']) === [], 422);
            abort_unless(empty($body['image']) || in_array($body['image'], $input['gateway_review_images'] ?? [], true), 422, 'Upload the review image through the app first.');
            $canonical['image'] = $body['image'] ?? null;
        } else {
            abort_unless($a->kind === 'media' && in_array($a->model, array_slice(self::MODELS, 1), true)
                && $body === ($input['gateway_media_input'] ?? null), 422, 'Media inputs must match the approved app request.');
        }
        $hash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
        abort_unless(hash_equals($a->request_hash, $hash), 409, 'Provider call differs from its recorded attempt.');
        if ($poll) {
            abort_unless(preg_match('/^[A-Za-z0-9_-]{1,160}$/D', (string) $a->prediction_id), 409, 'Prediction identity is not yet known.');
            $p = $this->http()->get('https://api.replicate.com/v1/predictions/'.$a->prediction_id)->throw()->json();
        } else {
            $p = app(DispatchJournal::class)->claim($attemptId);
            if (! $p) {
                $request = $a->kind === 'agent' ? ['prompt' => $body['prompt'], 'system_prompt' => $body['system'], 'max_tokens' => $body['maxTokens'],
                    ...(! empty($body['image']) ? ['image' => $body['image'], 'max_image_resolution' => .5] : [])] : $body;
                // One POST only. A lost acknowledgement remains unresolved.
                $p = $this->http()->withHeaders(['Cancel-After' => $a->kind === 'media' ? '300s' : '120s'])
                    ->post('https://api.replicate.com/v1/models/'.$a->model.'/predictions', ['input' => $request])->throw()->json();
                abort_unless(is_array($p), 502);
                app(DispatchJournal::class)->save($attemptId, $p);
            }
            abort_unless(preg_match('/^[A-Za-z0-9_-]{1,160}$/D', (string) ($p['id'] ?? '')), 502, 'Provider receipt has no ID.');
            if ($a->status === 'started') app(AttemptService::class)->bindPrediction($id, $lease, $attemptId, $p['id']);
            else abort_unless($a->prediction_id === $p['id'] && $a->status === 'succeeded', 409, 'This attempt needs reconciliation.');
        }
        abort_unless(($p['model'] ?? null) === $a->model && (! $poll || ($p['id'] ?? null) === $a->prediction_id), 502, 'Provider receipt mismatch.');
        if ($a->kind === 'agent' && ($p['status'] ?? '') === 'succeeded') {
            $version = $p['version'] ?? null;
            if ($version === 'hidden') $version = $this->http()->get('https://api.replicate.com/v1/models/'.$a->model)->throw()->json('latest_version.id');
            abort_unless($version === '459655107e29a683cb6deb73a9640cf9aeae39ea7c87803a2ae81c311f6ef44f', 409, 'Model contract changed; review before another call.');
        }
        return array_intersect_key($p, array_flip(['id', 'status', 'output', 'metrics']));
    }
}

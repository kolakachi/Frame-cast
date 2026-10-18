<?php

namespace App\Services\Generation\Video;

use App\Services\Generation\ProviderFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Seedance 2.5 through OpenRouter's video API (owner, 2026-10-18: a week's trial for UGC). A job is submitted, polled and
 * downloaded; ReplicateVeoAdapter hands its Seedance takes here while services.ugc.seedance_openrouter is on, with ids
 * prefixed "or:" so polling and cancelling find their way back.
 *
 * Seedance declines any picture it reads as a real person (InputImageSensitiveContentDetected.PrivacyInformation), at
 * submit and free of charge. That refusal is raised as FACE_DECLINED, so the chat can make the take on Omni instead.
 */
class OpenRouterVideoAdapter
{
    public const MODELS = ['seedance25' => 'bytedance/seedance-2.5'];

    /** In every error for a presenter Seedance would not render; the chat keys its Omni fallback on it. */
    public const FACE_DECLINED = 'The video model declined this segment: it would not render the presenter from their picture. Nothing was charged.';

    public function configured(): bool
    {
        return (string) config('services.openrouter.api_key', '') !== '';
    }

    /**
     * @param  list<string>  $referenceImages  data URIs or URLs, at most 9
     * @return string the OpenRouter job id
     */
    public function start(string $engine, string $prompt, int $seconds, ?string $startFrame, array $referenceImages, ?int $seed, string $resolution, string $aspectRatio, ?string $audioUrl = null): string
    {
        $model = self::MODELS[$engine] ?? throw new \InvalidArgumentException('OpenRouter has no '.$engine.' model here.');
        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'duration' => max(4, min(30, $seconds)),
            'resolution' => in_array($resolution, ['480p', '720p'], true) ? $resolution : '720p',
            'aspect_ratio' => $aspectRatio,
            'generate_audio' => true,
        ];
        if ($seed !== null) $body['seed'] = $seed;
        if ($startFrame !== null) {
            $body['frame_images'] = [['type' => 'image_url', 'image_url' => ['url' => $startFrame], 'frame_type' => 'first_frame']];
        }
        $refs = array_map(fn ($url) => ['type' => 'image_url', 'image_url' => ['url' => $url]], array_slice(array_values($referenceImages), 0, 9));
        if ($audioUrl !== null) $refs[] = ['type' => 'audio_url', 'audio_url' => ['url' => $audioUrl]];
        if ($refs !== []) $body['input_references'] = $refs;

        $response = $this->http()->timeout(120)->post($this->url('/videos'), $body);
        $id = (string) ($response->json('id') ?? '');
        if (! $response->successful() || $id === '') {
            $why = mb_substr($response->body(), 0, 400);
            if (self::faceDeclined($why)) {
                \Illuminate\Support\Facades\Log::info('openrouter video: presenter declined', ['model' => $model, 'error' => $why]);
                throw new ProviderFailed(self::FACE_DECLINED);
            }
            if (stripos($why, 'SensitiveContent') !== false) {
                throw new ProviderFailed('The video model declined this segment — its moderation flags some content even in tasteful ads. Nothing was charged; rephrase the framing and retry.');
            }
            \App\Services\Vendors\VendorAlerts::observe('openrouter', 'OpenRouter video submit failed: '.$why, $response->status(), ['kind' => 'video:'.$engine]);
            $message = 'Seedance submit failed: '.mb_substr($why, 0, 200);
            throw ProviderFailed::rejectedAtStart($response->status()) ? new ProviderFailed($message) : new \RuntimeException($message);
        }
        \App\Services\Vendors\VendorAlerts::recovered('openrouter');
        // The poll's answer names no model; the usage recorder reads it from here.
        Cache::put('openrouter-video-model:'.$id, $model, now()->addDays(3));

        return $id;
    }

    /** A URL the finished video downloads from (or a local file), null on timeout; throws on failure. */
    public function pollUntilDone(string $id, int $maxSeconds = 900): ?string
    {
        $deadline = time() + $maxSeconds;
        while (time() < $deadline) {
            $job = $this->http()->timeout(30)->get($this->url('/videos/'.$id))->json() ?? [];
            $status = (string) ($job['status'] ?? '');
            if ($status === 'completed') {
                // A signed storage link needs no key; the content endpoint does, so it is fetched here.
                $url = (string) ($job['urls'][0] ?? '');
                if ($url !== '') return $url;
                $path = tempnam(sys_get_temp_dir(), 'openrouter-').'.mp4';
                file_put_contents($path, $this->http()->timeout(300)->get($this->url('/videos/'.$id.'/content'), ['index' => 0])->body());
                return filesize($path) ? $path : null;
            }
            if (in_array($status, ['failed', 'cancelled', 'error'], true)) {
                $error = mb_substr((string) json_encode($job['error'] ?? $status), 0, 400);
                \Illuminate\Support\Facades\Log::info('openrouter video failed', ['id' => $id, 'error' => $error]);
                if (self::faceDeclined($error)) throw new ProviderFailed(self::FACE_DECLINED);
                if (stripos($error, 'sensitive') !== false) {
                    throw new ProviderFailed('The video model declined this segment — its moderation flags some content even in tasteful ads. Nothing was charged; rephrase the framing and retry.');
                }
                throw new ProviderFailed('Seedance generation failed: '.mb_substr($error, 0, 300));
            }
            sleep(10);
        }

        return null;
    }

    public static function faceDeclined(string $error): bool
    {
        return str_contains($error, 'PrivacyInformation') || stripos($error, 'may contain real person') !== false;
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken((string) config('services.openrouter.api_key'))->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.openrouter.url', 'https://openrouter.ai/api/v1'), '/').$path;
    }
}

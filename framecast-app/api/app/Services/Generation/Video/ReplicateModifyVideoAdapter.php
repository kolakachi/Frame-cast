<?php

namespace App\Services\Generation\Video;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Video-to-video restyling via luma/modify-video on Replicate (Ray-2): the
 * source clip plus an instruction — "make it night time, change the fox to a
 * cat" — re-rendered with the original motion, framing and timing kept.
 *
 * Learned the hard way, encoded here:
 * - Gen-4 Aleph is sunset; Luma Modify is the live model (30s / 100MB max).
 * - Data-URI video inputs are refused (E006), and Replicate's Files API only
 *   serves METADATA at its URL — the model fetches JSON and calls the input
 *   invalid. Sources are staged on the public B2 bucket instead, which works
 *   identically from local dev and prod.
 * - The model renders at 720p; smaller sources are upscaled before submit.
 */
class ReplicateModifyVideoAdapter
{
    public const MODEL = 'luma/modify-video';

    /** Engine key -> Replicate model. Aleph 2 passed content Luma's moderation refused. */
    public const ENGINES = [
        'luma' => 'luma/modify-video',
        'aleph2' => 'runwayml/aleph-2',
    ];

    public const MAX_SECONDS = 30;

    // Aleph 2 caps input at 16MB (Luma allows 100MB).
    public const ALEPH_MAX_BYTES = 16 * 1024 * 1024;

    public const MODES = [
        'adhere_1', 'adhere_2', 'adhere_3',
        'flex_1', 'flex_2', 'flex_3',
        'reimagine_1', 'reimagine_2', 'reimagine_3',
    ];

    public function providerKey(): string
    {
        return 'replicate:'.self::MODEL;
    }

    public function configured(): bool
    {
        return (string) config('services.replicate.api_token', '') !== '';
    }

    /**
     * Stage a local video on the public B2 bucket and return the URL the
     * model will fetch. Upscales below-720p sources first. B2 rejects canned
     * ACLs, so this is a raw S3 put with none.
     *
     * @return array{url: string, key: string}
     */
    public function uploadSource(string $localPath, string $engine = 'luma'): array
    {
        $prepared = $this->ensureMinResolution($localPath);
        // Aleph 2 refuses inputs over 16MB; recompress rather than reject.
        if ($engine === 'aleph2' && filesize($prepared) > self::ALEPH_MAX_BYTES - 512 * 1024) {
            $shrunk = sys_get_temp_dir().'/restyle-shrink-'.uniqid().'.mp4';
            $result = Process::timeout(300)->run([
                'ffmpeg', '-y', '-i', $prepared, '-c:v', 'libx264', '-preset', 'fast', '-crf', '26', '-c:a', 'copy', $shrunk,
            ]);
            if ($result->successful() && is_file($shrunk) && filesize($shrunk) < filesize($prepared)) {
                if ($prepared !== $localPath) {
                    @unlink($prepared);
                }
                $prepared = $shrunk;
            }
        }
        $key = 'restyle-sources/'.\Illuminate\Support\Str::uuid().'.mp4';

        try {
            $this->s3()->putObject([
                'Bucket' => (string) env('B2_BUCKET_NAME'),
                'Key' => $key,
                'Body' => fopen($prepared, 'rb'),
                'ContentType' => 'video/mp4',
            ]);
        } finally {
            if ($prepared !== $localPath) {
                @unlink($prepared);
            }
        }

        return [
            'url' => rtrim((string) env('B2_ENDPOINT'), '/').'/'.env('B2_BUCKET_NAME').'/'.$key,
            'key' => $key,
        ];
    }

    /** Remove a staged source once the run is over — it was never an asset. */
    public function removeSource(string $key): void
    {
        try {
            $this->s3()->deleteObject(['Bucket' => (string) env('B2_BUCKET_NAME'), 'Key' => $key]);
        } catch (\Throwable) {
            // A leftover staging file is cheap; failing the job over it is not.
        }
    }

    private function s3(): \Aws\S3\S3Client
    {
        return new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => (string) env('B2_REGION'),
            'endpoint' => (string) env('B2_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => (string) env('B2_KEY_ID'), 'secret' => (string) env('B2_APP_KEY')],
        ]);
    }

    /** Submit the restyle and return the prediction id. Throws on refusal. */
    public function start(string $videoUrl, string $prompt, string $mode = 'flex_1', string $engine = 'luma'): string
    {
        $model = self::ENGINES[$engine] ?? self::ENGINES['luma'];
        $mode = in_array($mode, self::MODES, true) ? $mode : 'flex_1';
        // Aleph 2 has no adherence knob — the prompt is the whole control.
        $input = $engine === 'aleph2'
            ? ['video' => $videoUrl, 'prompt' => $prompt]
            : ['video' => $videoUrl, 'prompt' => $prompt, 'mode' => $mode];

        $response = Http::withToken((string) config('services.replicate.api_token'))
            ->timeout(60)
            ->post('https://api.replicate.com/v1/models/'.$model.'/predictions', [
                'input' => $input,
            ]);

        $id = (string) data_get($response->json(), 'id');
        if (! $response->successful() || $id === '') {
            throw new \RuntimeException('Restyle submit failed: '.mb_substr($response->body(), 0, 200));
        }

        return $id;
    }

    /** Output video URL on success, null on timeout; throws on model failure. */
    public function pollUntilDone(string $predictionId, int $maxSeconds = 850): ?string
    {
        $deadline = time() + $maxSeconds;
        while (time() < $deadline) {
            $p = Http::withToken((string) config('services.replicate.api_token'))
                ->timeout(30)
                ->get('https://api.replicate.com/v1/predictions/'.$predictionId)
                ->json();
            $status = (string) ($p['status'] ?? '');
            if ($status === 'succeeded') {
                $output = $p['output'] ?? null;

                return is_array($output) ? (string) ($output[0] ?? '') : (string) $output;
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                $error = (string) ($p['error'] ?? 'no detail');
                // E006 is Luma's input moderation — the fox reel's knife
                // taught us it reads as 'invalid input' but means 'declined'.
                if (str_contains($error, 'E006') || str_contains($error, 'E005')) {
                    throw new \RuntimeException('The video model declined this clip — its moderation flags some content (weapons, people it deems sensitive) even in cartoons. Nothing was charged. A different clip, or trimming the flagged moment, usually gets through.');
                }
                throw new \RuntimeException('Restyle failed: '.mb_substr($error, 0, 300));
            }
            sleep(10);
        }

        return null;
    }

    /** The model refuses sub-720p sources; upscale to 720 on the short side. */
    private function ensureMinResolution(string $path): string
    {
        $probe = Process::timeout(30)->run([
            'ffprobe', '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height', '-of', 'csv=p=0', $path,
        ]);
        [$w, $h] = array_map('intval', array_pad(explode(',', trim($probe->output())), 2, 0));
        if ($w >= 720 && $h >= 720) {
            return $path;
        }
        if ($w === 0 || $h === 0) {
            return $path; // let the model give the real error
        }

        $scale = $w <= $h ? 'scale=720:-2:flags=lanczos' : 'scale=-2:720:flags=lanczos';
        $out = sys_get_temp_dir().'/restyle-src-'.uniqid().'.mp4';
        $result = Process::timeout(300)->run([
            'ffmpeg', '-y', '-i', $path, '-vf', $scale,
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '20', '-c:a', 'copy', $out,
        ]);
        if (! $result->successful() || ! is_file($out)) {
            return $path;
        }

        return $out;
    }
}

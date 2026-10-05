<?php

namespace App\Services\Create\References;

use Illuminate\Support\Facades\{Http, Log, Process};

/**
 * The reference reading on another provider's model, for an A/B against Claude (create:study-ab --models). Takes the
 * same content blocks Claude gets (text and base64 JPEG images, in order) and returns the parsed JSON with its usage
 * and its cost at list price, or null. Never used by a real study.
 */
class OtherReader
{
    /** @param array{0: float, 1: float} $rates micro-dollars per input and per output token */
    public static function ask(string $model, array $content, array $rates): ?array
    {
        [$provider, $name] = explode(':', $model, 2);
        $name = preg_replace('/\+video$/', '', $name);
        try {
            [$text, $in, $out] = $provider === 'openai' ? self::openai($name, $content) : self::replicate($name, $content);
        } catch (\Throwable $e) {
            Log::warning('Create reference A/B: '.$model.' failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
            return null;
        }
        $a = strpos($text, '{'); $b = strrpos($text, '}');
        $json = $a !== false && $b !== false ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
        if (! is_array($json)) { Log::warning('Create reference A/B: '.$model.' replied without JSON', ['text' => mb_substr($text, 0, 300)]); return null; }
        return [$json, ['input_tokens' => $in, 'output_tokens' => $out, 'cost_microusd' => (int) ceil($in * $rates[0] + $out * $rates[1])]];
    }

    private static function openai(string $name, array $content): array
    {
        $parts = array_map(fn ($c) => $c['type'] === 'image'
            ? ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.$c['source']['data'], 'detail' => 'high']]
            : ['type' => 'text', 'text' => $c['text']], $content);
        $r = Http::withToken((string) config('services.openai.api_key'))->acceptJson()->timeout(600)
            ->post('https://api.openai.com/v1/chat/completions', ['model' => $name, 'max_tokens' => 16384, 'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'user', 'content' => $parts]]]);
        if (! $r->successful()) throw new \RuntimeException($r->status().' '.mb_substr($r->body(), 0, 300));
        return [(string) $r->json('choices.0.message.content'), (int) $r->json('usage.prompt_tokens'), (int) $r->json('usage.completion_tokens')];
    }

    /** Replicate's Gemini takes one prompt and at most 10 images: images are marked in the text and, past 10, stacked. */
    private static function replicate(string $name, array $content): array
    {
        $token = (string) config('services.replicate.api_token');
        $images = []; $text = ''; $videos = [];
        foreach ($content as $c) {
            if ($c['type'] === 'video') {
                // A data URI carries its type; an uploaded file's URL has no extension and Gemini cannot tell it is a video.
                $videos[] = 'data:video/mp4;base64,'.base64_encode((string) file_get_contents($c['path']));
            }
            elseif ($c['type'] === 'image') { $images[] = base64_decode($c['source']['data']); $text .= "\n[picture ".count($images)."]\n"; }
            else $text .= "\n".$c['text'];
        }
        $per = (int) max(1, ceil(count($images) / 10));
        if (! $images) $per = 1;
        $files = []; $dir = sys_get_temp_dir().'/other-reader-'.bin2hex(random_bytes(6)); @mkdir($dir, 0700, true);
        foreach (array_chunk($images, $per) as $g => $group) {
            foreach ($group as $k => $bytes) file_put_contents($dir."/{$g}-{$k}.jpg", $bytes);
            $path = $dir."/stack-{$g}.jpg";
            if (count($group) === 1) copy($dir."/{$g}-0.jpg", $path);
            else {
                $args = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y'];
                foreach (array_keys($group) as $k) array_push($args, '-i', $dir."/{$g}-{$k}.jpg");
                $filter = implode('', array_map(fn ($k) => "[{$k}:v]scale=1600:-2[v{$k}];", array_keys($group))).implode('', array_map(fn ($k) => "[v{$k}]", array_keys($group))).'vstack=inputs='.count($group);
                array_push($args, '-filter_complex', $filter, '-q:v', '3', $path);
                if (! Process::timeout(60)->run($args)->successful()) throw new \RuntimeException('Could not stack the sheets.');
            }
            $up = Http::withToken($token)->timeout(120)->attach('content', file_get_contents($path), 'stack-'.$g.'.jpg', ['Content-Type' => 'image/jpeg'])->post('https://api.replicate.com/v1/files');
            if (! $up->successful()) throw new \RuntimeException('Upload '.$up->status());
            $files[] = (string) $up->json('urls.get');
        }
        foreach (glob($dir.'/*') ?: [] as $f) @unlink($f); @rmdir($dir);
        // Say where each original picture went once they are stacked.
        if ($per > 1) $text = preg_replace_callback('/\[picture (\d+)\]/', fn ($m) => '[picture '.$m[1].': image '.(intdiv((int) $m[1] - 1, $per) + 1).', part '.(((int) $m[1] - 1) % $per + 1).' from the top]', $text);
        $r = Http::withToken($token)->withHeaders(['Prefer' => 'wait=60'])->timeout(120)
            ->post('https://api.replicate.com/v1/models/'.$name.'/predictions', ['input' => ['prompt' => trim($text), 'images' => $files, 'videos' => $videos, 'max_output_tokens' => 32000, 'dynamic_thinking' => true]]);
        if (! $r->successful()) throw new \RuntimeException('Start '.$r->status().' '.mb_substr($r->body(), 0, 300));
        $p = $r->json();
        for ($i = 0; $i < 120 && ! in_array($p['status'] ?? '', ['succeeded', 'failed', 'canceled'], true); $i++) {
            sleep(5);
            $p = Http::withToken($token)->timeout(60)->get((string) $p['urls']['get'])->json();
        }
        if (($p['status'] ?? '') !== 'succeeded') throw new \RuntimeException('Prediction '.($p['status'] ?? '?').' '.mb_substr((string) ($p['error'] ?? ''), 0, 200));
        $out = is_array($p['output']) ? implode('', $p['output']) : (string) $p['output'];
        $m = $p['metrics'] ?? [];
        return [$out, (int) ($m['token_input_count'] ?? $m['input_token_count'] ?? 0), (int) ($m['token_output_count'] ?? $m['output_token_count'] ?? 0)];
    }
}

<?php
namespace App\Services\Create\References;

use App\Services\Media\MediaTranscriptionService;
use Illuminate\Support\Facades\{Http, Process};

/**
 * What a reference video teaches, in words the planner and agent can use:
 * pacing from detected cuts, any speech, and a short style read of sampled
 * frames. It describes; it never copies the reference into an output.
 */
class ReferenceAnalyzer
{
    public function analyze(string $file, array $source, float $duration): array
    {
        $probe = json_decode(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_type,width,height', '-of', 'json', $file])->output(), true) ?: [];
        $streams = collect($probe['streams'] ?? []);
        $video = $streams->firstWhere('codec_type', 'video') ?? [];
        $hasAudio = $streams->contains('codec_type', 'audio');
        $cuts = $this->cuts($file);
        $shots = count($cuts) + 1;
        $out = ['duration_seconds' => round($duration, 1), 'width' => $video['width'] ?? null, 'height' => $video['height'] ?? null, 'has_audio' => $hasAudio,
            'cuts' => $cuts, 'shots' => $shots, 'average_shot_seconds' => $duration > 0 ? round($duration / $shots, 1) : null, 'transcript' => null, 'notes' => null];
        if ($hasAudio) {
            try {
                $t = app(MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps($file, 'video/mp4');
                if (($t['provider_key'] ?? '') !== 'local_fallback' && trim((string) $t['transcript']) !== '') $out['transcript'] = mb_substr(trim($t['transcript']), 0, 2000);
            } catch (\Throwable) { /* music-only or no speech: pacing and look still apply */ }
        }
        if (config('create.mode') !== 'fixture' && (string) config('services.anthropic.key') !== '') {
            $sheet = $this->contactSheet($file, $duration);
            if ($sheet) $out = [...$out, ...$this->notes($sheet, $source, $out)];
        }
        return $out;
    }

    /** Scene-change times in seconds, capped so a strobing clip cannot flood context. */
    private function cuts(string $file): array
    {
        $r = Process::timeout(60)->run(['ffmpeg', '-hide_banner', '-nostats', '-i', $file, '-an', '-vf', "scale=320:-2,select='gt(scene,0.3)',metadata=print", '-f', 'null', '-']);
        preg_match_all('/pts_time:([0-9.]+)/', $r->errorOutput().$r->output(), $m);
        return array_slice(array_map(fn ($t) => round((float) $t, 2), $m[1] ?? []), 0, 120);
    }

    private function contactSheet(string $file, float $duration): ?string
    {
        $sheet = dirname($file).'/sheet.jpg';
        $fps = max(0.01, 8 / max(1.0, $duration));
        $r = Process::timeout(60)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $file, '-vf', "fps={$fps},scale=360:-2,tile=4x2", '-frames:v', '1', '-q:v', '4', $sheet]);
        return $r->successful() && is_file($sheet) ? $sheet : null;
    }

    private function notes(string $sheet, array $source, array $facts): array
    {
        $prompt = "These are 8 frames sampled in order from a public {$source['platform']} video a user shared as a style reference for their own video. "
            ."Detected: {$facts['shots']} shots in {$facts['duration_seconds']} s, average shot {$facts['average_shot_seconds']} s"
            .($facts['transcript'] ? ', speech: "'.mb_substr($facts['transcript'], 0, 400).'"' : ', no usable speech').". "
            .'Describe what makes it work so a designer can borrow the approach, not the content. Reply with JSON only: '
            .'{"summary": "one sentence", "look": "under 25 words", "palette": ["#hex", ...up to 5], "type": "typography, under 20 words or none", '
            .'"motion": "under 25 words", "structure": "how it opens, builds and ends, under 30 words", "borrow": ["up to 4 techniques"], '
            .'"avoid_copying": ["specific characters, logos, text or footage that belong to the original"], '
            .'"fingerprint": {"structure": "under 15 words", "opening": "under 15 words", "signature_shot": "the one move it is remembered for, under 15 words", "camera_path": "under 12 words", "score_shape": "unknown: no audio supplied to this visual analysis", "ending": "under 12 words"}, '
            .'"recipes": ["up to 3 named motion moves worth building, e.g. giant-type wipe, stamp, field flip, one shape morphing"]}';
        $model = str_starts_with((string) config('create.agent_model'), 'claude-') ? (string) config('create.agent_model') : 'claude-opus-5-5';
        $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', ['model' => $model, 'max_tokens' => 1500, 'output_config' => ['effort' => 'low'],
                'messages' => [['role' => 'user', 'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode(file_get_contents($sheet))]],
                    ['type' => 'text', 'text' => $prompt]]]]]);
        if (! $r->successful()) { \App\Services\Vendors\VendorAlerts::observe('anthropic', $r->body(), $r->status()); return []; }
        $text = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $json = json_decode(substr($text, (int) strpos($text, '{'), strrpos($text, '}') - (int) strpos($text, '{') + 1), true);
        if (! is_array($json)) return [];
        $s = fn ($v, $n) => mb_substr(trim((string) $v), 0, $n);
        $list = fn ($v, $n, $len) => array_values(array_slice(array_filter(array_map(fn ($x) => $s($x, $len), (array) $v)), 0, $n));
        $u = $r->json('usage', []);
        return ['notes' => ['summary' => $s($json['summary'] ?? '', 240), 'look' => $s($json['look'] ?? '', 200), 'palette' => array_values(array_filter($list($json['palette'] ?? [], 5, 9), fn ($c) => preg_match('/^#[0-9a-fA-F]{3,8}$/', $c))),
                'type' => $s($json['type'] ?? '', 160), 'motion' => $s($json['motion'] ?? '', 200), 'structure' => $s($json['structure'] ?? '', 240),
                'borrow' => $list($json['borrow'] ?? [], 4, 140), 'avoid_copying' => $list($json['avoid_copying'] ?? [], 6, 140),
                'fingerprint' => collect(['structure', 'opening', 'signature_shot', 'camera_path', 'score_shape', 'ending'])->mapWithKeys(fn ($k) => [$k => $s(data_get($json, 'fingerprint.'.$k, ''), 120)])->filter()->all(),
                'recipes' => $list($json['recipes'] ?? [], 3, 100)],
            'notes_model' => $model, 'notes_cost_microusd' => (int) ceil(((int) ($u['input_tokens'] ?? 0)) * 4 + ((int) ($u['output_tokens'] ?? 0)) * 20)];
    }
}

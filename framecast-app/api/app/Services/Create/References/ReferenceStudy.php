<?php

namespace App\Services\Create\References;

use App\Models\Asset;
use App\Services\Media\{MediaTranscriptionService, StorageService};
use Illuminate\Support\Facades\{Cache, Http, Process, Storage};
use Illuminate\Support\Str;

/**
 * A reference video studied once, when it is attached, so planning reads a
 * finished study instead of sampling inside the planning request.
 *
 * Local and deterministic: every shot (cut detection), frames inside every
 * shot, every-frame close-ups where the picture changes inside a shot
 * (stickers, counters, typing), and the speech timing map (word-timed
 * transcript, pauses, pace). One model call turns the sheets and the
 * transcript into a timed list of the moments on screen. Cached on the asset
 * by source hash. It describes the reference; nothing is copied into an output.
 */
class ReferenceStudy
{
    public const VERSION = 1;
    private const MAX_SAMPLES = 80;
    private const PER_SHEET = 20;

    /** The study for this asset's current bytes, making it on first use; null for non-video or unreadable sources. */
    public function forAsset(Asset $asset): ?array
    {
        if ($asset->asset_type !== 'video' || ! $asset->storage_url) return null;
        $bytes = app(StorageService::class)->get((string) $asset->storage_url);
        if (! is_string($bytes) || $bytes === '') return null;
        $sha = hash('sha256', $bytes);
        $have = data_get($asset->metadata_json, 'reference_study');
        if (self::reusable($have, $sha)) return $have;
        // One study per source at a time; a second caller waits for the first instead of paying twice.
        return Cache::lock('create-reference-study:'.$sha, 600)->block(300, function () use ($asset, $bytes, $sha) {
            $asset->refresh();
            $have = data_get($asset->metadata_json, 'reference_study');
            if (self::reusable($have, $sha)) return $have;
            $dir = sys_get_temp_dir().'/create-study-'.Str::uuid();
            @mkdir($dir, 0700, true);
            try {
                file_put_contents($dir.'/in.mp4', $bytes);
                $study = $this->study($dir.'/in.mp4', $sha, $dir);
                $meta = (array) $asset->metadata_json; $meta['reference_study'] = $study;
                $asset->forceFill(['metadata_json' => $meta])->save();
                return $study;
            } finally {
                foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
                @rmdir($dir);
            }
        });
    }

    /** A cached study is reused unless its moment list failed (a provider outage or billing stop): then it is made again. */
    public static function reusable(mixed $have, string $sha): bool
    {
        return is_array($have) && ($have['source_sha256'] ?? null) === $sha && ($have['version'] ?? 0) === self::VERSION && ($have['moments_status'] ?? 'ok') !== 'failed';
    }

    /** The full study of a local file. Sheets are stored under create/reference-studies/<sha>/. */
    public function study(string $file, string $sha, string $work): array
    {
        $probe = json_decode(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration:stream=codec_type,width,height,avg_frame_rate', '-of', 'json', $file])->output(), true) ?: [];
        $streams = collect($probe['streams'] ?? []);
        $video = $streams->firstWhere('codec_type', 'video') ?? [];
        $duration = round((float) data_get($probe, 'format.duration', 0), 2);
        [$num, $den] = array_map('floatval', explode('/', (string) ($video['avg_frame_rate'] ?? '0/1')) + [1 => 1]);
        $hasAudio = $streams->contains('codec_type', 'audio');
        $scores = $this->changeScores($file);
        $cuts = self::cuts($scores);
        $shots = self::shots($cuts, $duration);
        $windows = self::changeWindows($scores, $shots);
        $samples = self::samples($shots, $windows, $duration);
        $sheets = $this->sheets($file, $samples, $sha, $work);
        $speech = $hasAudio ? $this->speech($file, $duration) : null;
        $study = ['version' => self::VERSION, 'source_sha256' => $sha, 'duration_seconds' => $duration, 'fps' => $den > 0 ? round($num / $den, 2) : null,
            'width' => $video['width'] ?? null, 'height' => $video['height'] ?? null, 'has_audio' => $hasAudio,
            'shots' => $shots, 'cuts' => $cuts, 'change_windows' => $windows, 'samples' => $samples, 'sheets' => $sheets, 'speech' => $speech,
            'pacing' => self::pacing($shots, $duration, $speech), 'moments' => [], 'patterns' => null, 'summary' => null,
            'coverage' => count($samples).' frames covering all '.count($shots).' shots and '.count($windows).' moments of change; '.($speech ? 'speech transcribed with word times' : 'no speech found').'. Not every frame; the audio is described from the transcript, not listened to.'];
        $study['moments_status'] = 'skipped';
        if (config('create.mode') !== 'fixture' && (string) config('services.anthropic.key') !== '' && $sheets) {
            $found = $this->moments($study);
            $study = [...$study, ...$found, 'moments_status' => $found ? 'ok' : 'failed'];
        }
        $study['moments'] = self::withSpokenDelay($study['moments'], $speech);
        $study['pacing']['text_to_speech_delay_seconds'] = self::medianDelay($study['moments']);
        return $study;
    }

    /** Per-frame change (0..1) at 10 frames a second: [[seconds, score], ...]. */
    private function changeScores(string $file): array
    {
        $r = Process::timeout(120)->run(['ffmpeg', '-hide_banner', '-nostats', '-i', $file, '-an', '-vf', "fps=10,scale=160:-2,select='gte(scene,0)',metadata=print", '-f', 'null', '-']);
        $out = []; $t = null;
        foreach (preg_split('/\R/', $r->errorOutput().$r->output()) as $line) {
            if (preg_match('/pts_time:([0-9.]+)/', $line, $m)) $t = (float) $m[1];
            elseif ($t !== null && preg_match('/scene_score=([0-9.]+)/', $line, $m)) { $out[] = [round($t, 2), (float) $m[1]]; $t = null; }
        }
        return $out;
    }

    /** Hard cuts: a large change, merged when they cluster. */
    public static function cuts(array $scores, float $threshold = 0.3): array
    {
        $cuts = [];
        foreach ($scores as [$t, $s]) if ($s > $threshold && $t > 0.15 && (! $cuts || $t - end($cuts) > 0.25)) $cuts[] = $t;
        return array_slice($cuts, 0, 120);
    }

    /** @return array<int, array{0: float, 1: float}> */
    public static function shots(array $cuts, float $duration): array
    {
        $bounds = [0.0, ...$cuts, $duration];
        $shots = [];
        for ($i = 0; $i < count($bounds) - 1; $i++) if ($bounds[$i + 1] - $bounds[$i] > 0.05) $shots[] = [round($bounds[$i], 2), round($bounds[$i + 1], 2)];
        return $shots ?: [[0.0, $duration]];
    }

    /**
     * Where the picture keeps changing inside a shot (a sticker popping in, a counter running, text typing on):
     * runs of at least three changing frames away from the cut, ranked by how much changes. At most eight.
     */
    public static function changeWindows(array $scores, array $shots, float $moving = 0.012): array
    {
        $windows = [];
        foreach ($shots as [$s, $e]) {
            $run = [];
            $flush = function () use (&$run, &$windows) {
                if (count($run) >= 3) $windows[] = ['start' => max(0, round($run[0][0] - 0.1, 2)), 'end' => round(min(end($run)[0] + 0.1, $run[0][0] + 1.5), 2), 'weight' => round(array_sum(array_column($run, 1)), 3)];
                $run = [];
            };
            foreach ($scores as [$t, $v]) {
                if ($t <= $s + 0.2 || $t >= $e - 0.1) continue;
                if ($v >= $moving) $run[] = [$t, $v]; else $flush();
            }
            $flush();
        }
        usort($windows, fn ($a, $b) => $b['weight'] <=> $a['weight']);
        $windows = array_slice($windows, 0, 8);
        usort($windows, fn ($a, $b) => $a['start'] <=> $b['start']);
        return $windows;
    }

    /** Frames to look at: inside every shot (more for longer shots) plus five across each moment of change. */
    public static function samples(array $shots, array $windows, float $duration): array
    {
        $must = []; $fill = [];
        foreach ($windows as $w) for ($i = 0; $i < 5; $i++) $must[] = $w['start'] + ($w['end'] - $w['start']) * ($i + 0.5) / 5;
        foreach ($shots as [$s, $e]) {
            $n = max(1, min(6, (int) ceil(($e - $s) / 1.5)));
            $must[] = $s + ($e - $s) * 0.5;
            for ($i = 0; $i < $n; $i++) $fill[] = $s + ($e - $s) * ($i + 0.5) / $n;
        }
        $pick = [];
        foreach ([...$must, ...$fill] as $t) {
            $t = round(min(max(0.0, $t), max(0.0, $duration - 0.05)), 2);
            if (count($pick) >= self::MAX_SAMPLES) break;
            if (! collect($pick)->contains(fn ($p) => abs($p - $t) < 0.08)) $pick[] = $t;
        }
        sort($pick);
        return $pick;
    }

    /** Sheets of twenty frames (five across), stored for the planner; each sheet records the seconds of its cells. */
    private function sheets(string $file, array $samples, string $sha, string $work): array
    {
        $out = [];
        foreach (array_chunk($samples, self::PER_SHEET) as $k => $times) {
            foreach (glob($work.'/c-*.jpg') ?: [] as $f) @unlink($f);
            foreach ($times as $i => $t) {
                Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-ss', (string) $t, '-i', $file, '-frames:v', '1', '-vf', 'scale=384:216:force_original_aspect_ratio=decrease,pad=384:216:(ow-iw)/2:(oh-ih)/2:black', '-q:v', '4', sprintf('%s/c-%02d.jpg', $work, $i)]);
            }
            $rows = (int) ceil(count($times) / 5);
            $r = Process::timeout(60)->run(['ffmpeg', '-v', 'error', '-y', '-framerate', '1', '-i', $work.'/c-%02d.jpg', '-vf', "tile=5x{$rows}:padding=2:color=black", '-frames:v', '1', '-q:v', '4', $work.'/sheet.jpg']);
            if (! $r->successful() || ! is_file($work.'/sheet.jpg')) continue;
            $path = 'create/reference-studies/'.$sha.'/sheet-'.($k + 1).'.jpg';
            Storage::disk('local')->put($path, (string) file_get_contents($work.'/sheet.jpg'));
            $out[] = ['path' => $path, 'times' => $times];
        }
        return $out;
    }

    /** Word-timed speech, the pauses between phrases, and how much of the video has a voice. */
    private function speech(string $file, float $duration): ?array
    {
        try { $t = app(MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps($file, 'video/mp4'); }
        catch (\Throwable) { return null; }
        if (($t['provider_key'] ?? '') === 'local_fallback') return null;
        $words = array_values(array_filter(array_map(fn ($w) => [trim((string) ($w['text'] ?? '')), round((float) ($w['start'] ?? 0), 2), round((float) ($w['end'] ?? 0), 2)], (array) ($t['words'] ?? [])), fn ($w) => $w[0] !== '' && $w[2] >= $w[1]));
        if (! $words) return trim((string) ($t['transcript'] ?? '')) !== '' ? ['text' => mb_substr(trim($t['transcript']), 0, 2000), 'words' => [], 'pauses' => [], 'speech_seconds' => null] : null;
        $pauses = [];
        for ($i = 1; $i < count($words); $i++) if ($words[$i][1] - $words[$i - 1][2] >= 0.5) $pauses[] = ['at' => $words[$i - 1][2], 'seconds' => round($words[$i][1] - $words[$i - 1][2], 2), 'before' => $words[$i][0]];
        $speech = round(min($duration, end($words)[2] - $words[0][1] - array_sum(array_column($pauses, 'seconds'))), 2);
        return ['text' => mb_substr(trim((string) ($t['transcript'] ?? implode(' ', array_column($words, 0)))), 0, 2000), 'words' => array_slice($words, 0, 600),
            'pauses' => array_slice($pauses, 0, 24), 'speech_seconds' => $speech, 'first_word_at' => $words[0][1], 'last_word_at' => end($words)[2]];
    }

    public static function pacing(array $shots, float $duration, ?array $speech): array
    {
        $lengths = array_map(fn ($s) => $s[1] - $s[0], $shots);
        $words = count($speech['words'] ?? []);
        return ['shots' => count($shots), 'average_shot_seconds' => $lengths ? round(array_sum($lengths) / count($lengths), 2) : null,
            'cuts_per_10_seconds' => $duration > 0 ? round((count($shots) - 1) / $duration * 10, 1) : null,
            'words_per_second' => $words && ($speech['speech_seconds'] ?? 0) > 0 ? round($words / $speech['speech_seconds'], 2) : null,
            'speech_share' => $words && $duration > 0 ? round(($speech['speech_seconds'] ?? 0) / $duration, 2) : null,
            'pauses_over_half_second' => count($speech['pauses'] ?? [])];
    }

    /** For each moment with on-screen text, how long after its first word is spoken it appears (negative: before). */
    public static function withSpokenDelay(array $moments, ?array $speech): array
    {
        $norm = fn ($s) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $s));
        $words = $speech['words'] ?? [];
        foreach ($moments as &$m) {
            $first = collect(preg_split('/\s+/', (string) ($m['on_screen_text'] ?? '')))->map($norm)->first(fn ($w) => mb_strlen($w) >= 3);
            if (! $first || ! $words) continue;
            $match = collect($words)->filter(fn ($w) => $norm($w[0]) === $first && abs($w[1] - $m['start']) <= 3)->sortBy(fn ($w) => abs($w[1] - $m['start']))->first();
            if ($match) $m['text_after_spoken_seconds'] = round($m['start'] - $match[1], 2);
        }
        return $moments;
    }

    public static function medianDelay(array $moments): ?float
    {
        $d = array_values(array_filter(array_column($moments, 'text_after_spoken_seconds'), 'is_numeric'));
        if (! $d) return null;
        sort($d);
        $n = count($d);
        return round($n % 2 ? $d[intdiv($n, 2)] : ($d[$n / 2 - 1] + $d[$n / 2]) / 2, 2);
    }

    /** One model call: the sheets and the speech become a timed list of moments and the reference's patterns. */
    private function moments(array $study): array
    {
        $content = [];
        foreach (array_slice($study['sheets'], 0, 4) as $i => $sheet) {
            $bytes = Storage::disk('local')->get($sheet['path']);
            if (! is_string($bytes) || $bytes === '') continue;
            $content[] = ['type' => 'text', 'text' => 'Sheet '.($i + 1).': cells left to right, then down, at seconds '.json_encode($sheet['times'])];
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($bytes)]];
        }
        $words = collect($study['speech']['words'] ?? [])->map(fn ($w) => $w[1].' '.$w[0])->implode(' | ');
        $content[] = ['type' => 'text', 'text' => "This is a reference video a user attached so their own video can borrow its approach (never its content). It runs {$study['duration_seconds']} s. "
            .'Shots (start-end seconds): '.json_encode($study['shots']).'. Moments where the picture changes inside a shot: '.json_encode(array_map(fn ($w) => [$w['start'], $w['end']], $study['change_windows'])).'. '
            .($words !== '' ? 'Speech, each word with its start second: '.mb_substr($words, 0, 6000).'. ' : 'No speech. ')
            .'List every distinct moment a viewer would notice, in order: each headline or text card, each UI screen or zoom, stickers, memes, emoji, counters, logos, characters, transitions and the close. '
            .'Short moments count (a sticker on screen for half a second is a moment). Reply with JSON only: '
            .'{"summary": "one sentence on how it works", "moments": [{"start": seconds, "end": seconds, "kind": "hook|text|stat|ui|zoom|sticker|character|transition|cta|logo|other", '
            .'"on_screen_text": "exact words on screen or empty", "visual": "what is shown, under 20 words", "motion": "how it moves or changes, under 15 words", '
            .'"transition_in": "cut|wipe|zoom|fade|whip|none|unknown", "spoken": "words said during it or empty"}], '
            .'"patterns": {"text_reveal": "how text appears relative to the voice", "emphasis": "how key words are emphasised", "pacing": "rhythm of holds and changes", "signature": "the move it is remembered for"}}. At most 30 moments.'];
        $model = str_starts_with((string) config('create.agent_model'), 'claude-') ? (string) config('create.agent_model') : 'claude-opus-5-5';
        try {
            $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(180)
                ->post('https://api.anthropic.com/v1/messages', ['model' => $model, 'max_tokens' => 8000, 'output_config' => ['effort' => 'low'], 'messages' => [['role' => 'user', 'content' => $content]]]);
        } catch (\Throwable) { return []; }
        if (! $r->successful()) { \Illuminate\Support\Facades\Log::warning('Create reference study: moment list failed', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]); return []; }
        $text = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $start = strpos($text, '{'); $end = strrpos($text, '}');
        $json = $start !== false && $end !== false ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        if (! is_array($json)) return [];
        $u = $r->json('usage', []);
        return ['moments' => self::normalizeMoments((array) ($json['moments'] ?? []), (float) $study['duration_seconds']),
            'patterns' => collect(['text_reveal', 'emphasis', 'pacing', 'signature'])->mapWithKeys(fn ($k) => [$k => mb_substr(trim((string) data_get($json, 'patterns.'.$k, '')), 0, 200)])->filter()->all() ?: null,
            'summary' => mb_substr(trim((string) ($json['summary'] ?? '')), 0, 300) ?: null,
            'model' => $model, 'cost_microusd' => (int) ceil(((int) ($u['input_tokens'] ?? 0)) * 5 + ((int) ($u['output_tokens'] ?? 0)) * 25)];
    }

    public static function normalizeMoments(array $raw, float $duration): array
    {
        $s = fn ($v, $n) => mb_substr(trim((string) $v), 0, $n);
        $kinds = ['hook', 'text', 'stat', 'ui', 'zoom', 'sticker', 'character', 'transition', 'cta', 'logo', 'other'];
        $out = [];
        foreach ($raw as $m) {
            if (! is_array($m) || ! is_numeric($m['start'] ?? null) || ! is_numeric($m['end'] ?? null)) continue;
            $a = round(max(0, min($duration, (float) $m['start'])), 2); $b = round(max($a, min($duration, (float) $m['end'])), 2);
            $out[] = ['id' => 'm'.(count($out) + 1), 'start' => $a, 'end' => $b, 'kind' => in_array($m['kind'] ?? '', $kinds, true) ? $m['kind'] : 'other',
                'on_screen_text' => $s($m['on_screen_text'] ?? '', 160), 'visual' => $s($m['visual'] ?? '', 160), 'motion' => $s($m['motion'] ?? '', 120),
                'transition_in' => $s($m['transition_in'] ?? '', 20), 'spoken' => $s($m['spoken'] ?? '', 200)];
            if (count($out) >= 30) break;
        }
        usort($out, fn ($x, $y) => $x['start'] <=> $y['start']);
        foreach ($out as $i => &$m) $m['id'] = 'm'.($i + 1);
        return $out;
    }
}

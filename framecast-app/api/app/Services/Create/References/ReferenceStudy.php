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
    // 2: frames labelled with the words being spoken; recurring systems; a purpose for each moment.
    public const VERSION = 4;
    public const METHODS = ['motion_graphics', 'render_3d', 'generated_video', 'footage', 'presenter', 'screen_recording', 'stock', 'still', 'audiogram'];
    public const VIDEO_TYPES = ['ugc_ad', 'faceless_explainer', 'product_ad', 'saas_motion', 'mascot_explainer', 'podcast_clip', 'other'];
    private const MAX_SAMPLES = 80;
    private const PER_SHEET = 20;
    /** A frame counts as a new look when it differs from the last kept frame by this share of its pixels' brightness. */
    public const LOOK_THRESHOLD = 0.02;

    /** The look threshold per effort: High keeps clearly different looks; Maximum keeps every distinct look. */
    public const THRESHOLDS = ['high' => 0.04, 'maximum' => 0.02];

    /**
     * How much of the reference is looked at, from the conversation's reference effort. standard: frames inside every
     * shot plus close-ups where the picture changes (at most 80). high: every clearly different look (4%). maximum:
     * every distinct look (2%), no cap, and a second close look at what the first pass could not explain.
     * CREATE_REFERENCE_COVERAGE overrides for calibration; while restrictions are off the default is maximum.
     */
    public static function coverageMode(?string $effort = null): string
    {
        $forced = ['every_look' => 'maximum', 'standard' => 'standard', 'high' => 'high', 'maximum' => 'maximum'][(string) config('create.reference_coverage', '')] ?? null;
        if ($forced) return $forced;
        if (in_array($effort, ['standard', 'high', 'maximum'], true)) return $effort;
        return \App\Services\Create\PilotPolicy::unlimited() ? 'maximum' : 'standard';
    }

    private static function sameMode(?string $a, string $b): bool { return (($a === 'every_look' ? 'maximum' : $a) ?? 'standard') === $b; }

    /** The study for this asset's current bytes, making it on first use; null for non-video or unreadable sources. */
    public function forAsset(Asset $asset, ?string $mode = null): ?array
    {
        $mode ??= self::coverageMode();
        if ($asset->asset_type !== 'video' || ! $asset->storage_url) return null;
        $bytes = app(StorageService::class)->get((string) $asset->storage_url);
        if (! is_string($bytes) || $bytes === '') return null;
        $sha = hash('sha256', $bytes);
        $have = data_get($asset->metadata_json, 'reference_study');
        if (self::reusable($have, $sha, $mode)) return $have;
        // One study per source at a time; a second caller waits for the first instead of paying twice.
        return Cache::lock('create-reference-study:'.$sha, 600)->block(300, function () use ($asset, $bytes, $sha, $mode) {
            $asset->refresh();
            $have = data_get($asset->metadata_json, 'reference_study');
            if (self::reusable($have, $sha, $mode)) return $have;
            $dir = sys_get_temp_dir().'/create-study-'.Str::uuid();
            @mkdir($dir, 0700, true);
            try {
                file_put_contents($dir.'/in.mp4', $bytes);
                $study = $this->study($dir.'/in.mp4', $sha, $dir, $mode);
                $meta = (array) $asset->metadata_json; $meta['reference_study'] = $study;
                $asset->forceFill(['metadata_json' => $meta])->save();
                return $study;
            } finally {
                foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
                @rmdir($dir);
            }
        });
    }

    public const LAYOUT_VERSION = 1;
    public const LAYOUT_ROLES = ['headline', 'text', 'card', 'tile', 'panel', 'phone', 'screen', 'button', 'mascot', 'logo', 'sticker', 'chart', 'cursor', 'image', 'other'];

    /**
     * Where each element sits at each moment (for copying a reference exactly): every moment's key frame is
     * read at a larger size and each visible element boxed as a share of the frame. Made once, cached with the study.
     */
    public function layoutForAsset(Asset $asset): ?array
    {
        $study = data_get($asset->metadata_json, 'reference_study');
        if (! is_array($study) || empty($study['moments'])) return null;
        if ((int) data_get($study, 'layout.version') === self::LAYOUT_VERSION && ! empty($study['layout']['moments'])) return $study['layout'];
        $bytes = app(StorageService::class)->get((string) $asset->storage_url);
        if (! is_string($bytes) || $bytes === '') return null;
        $dir = sys_get_temp_dir().'/create-layout-'.Str::uuid();
        @mkdir($dir, 0700, true);
        try {
            file_put_contents($dir.'/in.mp4', $bytes);
            $layout = $this->layout($dir.'/in.mp4', $study, $dir);
            if (! $layout) return null;
            $asset->refresh(); $meta = (array) $asset->metadata_json; $meta['reference_study']['layout'] = $layout;
            $asset->forceFill(['metadata_json' => $meta])->save();
            return $layout;
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }

    /**
     * How the picture is shaded, measured on full-resolution frames: ordered dither shows as 8x8 blocks of
     * near-pure black and white pixels that flip value at a fixed step (1 or 2 px). Returns the share of
     * non-blank blocks that are dithered, the step, and a label when it is clearly a dithered look.
     */
    private function treatment(string $file, array $samples): array
    {
        $times = array_values(array_filter(array_map('floatval', array_slice($samples, 0, 40)), fn ($t) => $t >= 0));
        $times = $times ? array_values(array_unique(array_map(fn ($i) => $times[(int) floor($i * (count($times) - 1) / 5)], range(0, 5)))) : [];
        $probe = json_decode(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'json', $file])->output(), true);
        $w = (int) data_get($probe, 'streams.0.width'); $hgt = (int) data_get($probe, 'streams.0.height');
        if (! $times || $w < 64 || $hgt < 64) return ['dither_share' => null];
        $blocks = 0; $dithered = 0; $step1 = 0; $step2 = 0;
        foreach ($times as $t) {
            $raw = Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-ss', (string) $t, '-i', $file, '-frames:v', '1', '-vf', 'format=gray', '-f', 'rawvideo', '-'])->output();
            if (strlen($raw) < $w * $hgt) continue;
            for ($by = 0; $by + 8 <= $hgt; $by += 16) for ($bx = 0; $bx + 8 <= $w; $bx += 16) {
                $bin = 0; $flips1 = 0; $flips2 = 0; $min = 255; $max = 0;
                for ($y = $by; $y < $by + 8; $y++) for ($x = $bx; $x < $bx + 8; $x++) {
                    $v = ord($raw[$y * $w + $x]); $min = min($min, $v); $max = max($max, $v);
                    if ($v < 50 || $v > 205) $bin++;
                    if ($x + 2 < $bx + 8) { $a = ord($raw[$y * $w + $x + 1]); $b = ord($raw[$y * $w + $x + 2]); if (abs($v - $a) > 150) $flips1++; if (abs($v - $b) > 150) $flips2++; }
                }
                if ($max - $min < 40) continue; // blank or flat
                $blocks++;
                if ($bin >= 56 && max($flips1, $flips2) >= 14) { $dithered++; $flips2 > $flips1 * 1.3 ? $step2++ : $step1++; }
            }
        }
        $share = $blocks ? round($dithered / $blocks, 3) : null;
        return ['dither_share' => $share, 'dither_step_px' => $dithered ? ($step2 > $step1 ? 2 : 1) : null, 'look' => $share !== null && $share >= 0.15 ? 'ordered_dither' : null];
    }

    /** The key time of a moment: late enough that its elements have arrived. */
    public static function keyTime(array $m, float $duration): float
    {
        $a = (float) ($m['start'] ?? 0); $b = (float) ($m['end'] ?? $a);
        return round(min(max(0, $duration - 0.05), $a + max(0, $b - $a) * 0.65), 2);
    }

    private function layout(string $file, array $study, string $work): ?array
    {
        $duration = (float) ($study['duration_seconds'] ?? 0);
        $moments = array_values(array_filter((array) $study['moments'], fn ($m) => isset($m['id'])));
        if (! $moments || $duration <= 0) return null;
        $font = collect(['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/lato/Lato-Medium.ttf'])->first(fn ($f) => is_file($f));
        $content = []; $sheets = 0;
        foreach (array_chunk($moments, 6) as $k => $chunk) {
            foreach (glob($work.'/l-*.jpg') ?: [] as $f) @unlink($f);
            foreach ($chunk as $i => $m) {
                $t = self::keyTime($m, $duration);
                // The frame at 640x360 with its moment id on a strip below it (never over the picture).
                // No padding inside the picture, so boxes measured on it are boxes on the real frame, whatever its shape.
                $vf = 'scale=640:360:force_original_aspect_ratio=decrease,pad=iw:ih+28:0:0:black';
                if ($font) { file_put_contents($work.'/label.txt', $m['id'].'  '.number_format($t, 2).'s'); $vf .= ",drawtext=fontfile={$font}:textfile={$work}/label.txt:x=8:y=h-22:fontsize=18:fontcolor=white"; }
                Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-ss', (string) $t, '-i', $file, '-frames:v', '1', '-vf', $vf, '-q:v', '3', sprintf('%s/l-%02d.jpg', $work, $i)]);
            }
            $rows = (int) ceil(count($chunk) / 3);
            $r = Process::timeout(60)->run(['ffmpeg', '-v', 'error', '-y', '-framerate', '1', '-i', $work.'/l-%02d.jpg', '-vf', "tile=3x{$rows}:padding=4:color=white", '-frames:v', '1', '-q:v', '3', $work.'/layout.jpg']);
            if (! $r->successful() || ! is_file($work.'/layout.jpg')) continue;
            $content[] = ['type' => 'text', 'text' => 'Sheet '.($k + 1).': moments '.implode(', ', array_column($chunk, 'id')).', left to right, top to bottom.'];
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode((string) file_get_contents($work.'/layout.jpg'))]];
            $sheets++;
        }
        if (! $sheets) return null;
        $content[] = ['type' => 'text', 'text' => 'Each cell is one moment of a reference video at its key time; its moment id is on the strip below the picture. '
            .'For every cell, list each visible element (at most 10, the most important first) with its role ('.implode(', ', self::LAYOUT_ROLES).'), a short label of what it is (e.g. "checkout tile", "mascot bust", "headline: Got something to sell?"), '
            .'and its box as fractions of that picture (not the strip): [x, y, width, height], x and y from the top left, each 0 to 1, to two decimals. Measure carefully: the boxes are used to place the same elements in the same slots. '
            .'Also give background: the picture\'s background in a few words. Reply with JSON only: {"moments": [{"moment": "m1", "background": "...", "elements": [{"role": "...", "label": "...", "box": [0.1, 0.2, 0.3, 0.4]}]}]}.'];
        $model = str_starts_with((string) config('create.agent_model'), 'claude-') ? (string) config('create.agent_model') : 'claude-opus-5-5';
        [$json, $usage] = $this->ask($model, $content, true, 'high') ?? [null, []];
        if (! is_array($json)) return null;
        $ids = array_column($moments, 'id'); $out = [];
        foreach ((array) ($json['moments'] ?? []) as $row) {
            if (! is_array($row) || ! in_array($row['moment'] ?? null, $ids, true)) continue;
            $els = [];
            foreach (array_slice((array) ($row['elements'] ?? []), 0, 10) as $e) {
                $raw = array_map('floatval', array_slice(array_values((array) ($e['box'] ?? [])), 0, 4));
                if (count($raw) !== 4 || $raw[2] <= 0 || $raw[3] <= 0) continue;
                [$x, $y] = [max(0, min(1, $raw[0])), max(0, min(1, $raw[1]))];
                $b = [round($x, 3), round($y, 3), round(max(0.005, min(1 - $x, $raw[2])), 3), round(max(0.005, min(1 - $y, $raw[3])), 3)];
                $els[] = ['role' => in_array($e['role'] ?? '', self::LAYOUT_ROLES, true) ? $e['role'] : 'other', 'label' => mb_substr(trim((string) ($e['label'] ?? '')), 0, 80), 'box' => $b];
            }
            $m = collect($moments)->firstWhere('id', $row['moment']);
            $out[] = ['moment' => $row['moment'], 'at' => self::keyTime($m, $duration), 'background' => mb_substr(trim((string) ($row['background'] ?? '')), 0, 80), 'elements' => $els];
        }
        return $out ? ['version' => self::LAYOUT_VERSION, 'moments' => $out, 'cost_microusd' => self::cost($usage)] : null;
    }

    /** A cached study is reused unless its moment list failed (a provider outage or billing stop): then it is made again. */
    public static function reusable(mixed $have, string $sha, ?string $mode = null): bool
    {
        return is_array($have) && ($have['source_sha256'] ?? null) === $sha && ($have['version'] ?? 0) === self::VERSION && ($have['moments_status'] ?? 'ok') !== 'failed'
            && self::sameMode($have['coverage_mode'] ?? null, $mode ?? self::coverageMode());
    }

    /** The full study of a local file. Sheets are stored under create/reference-studies/<sha>/. */
    public function study(string $file, string $sha, string $work, ?string $mode = null): array
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
        $fps = $den > 0 ? $num / $den : 0.0;
        $mode ??= self::coverageMode();
        $looks = isset(self::THRESHOLDS[$mode]) ? $this->looks($file, $fps, self::THRESHOLDS[$mode]) : null;
        $samples = $looks !== null ? self::lookSamples($looks, $duration, $fps) : self::samples($shots, $windows, $duration);
        $speech = $hasAudio ? $this->speech($file, $duration) : null;
        $sheets = $this->sheets($file, $samples, $sha, $work, $speech);
        $study = ['version' => self::VERSION, 'source_sha256' => $sha, 'duration_seconds' => $duration, 'fps' => $den > 0 ? round($num / $den, 2) : null,
            'width' => $video['width'] ?? null, 'height' => $video['height'] ?? null, 'has_audio' => $hasAudio,
            'coverage_mode' => $mode, 'frames' => $looks['frames'] ?? null, 'looks' => $looks ? count($looks['looks']) : null,
            'shots' => $shots, 'cuts' => $cuts, 'change_windows' => $windows, 'samples' => $samples, 'sheets' => $sheets, 'speech' => $speech,
            'pacing' => self::pacing($shots, $duration, $speech), 'music' => $hasAudio ? self::beatMap($this->lowBand($file), $cuts) : null, 'moments' => [], 'systems' => [], 'patterns' => null, 'summary' => null,
            'coverage' => ($looks !== null ? count($samples).' frames, one for every distinct look in '.$looks['frames'].' frames' : count($samples).' frames covering all '.count($shots).' shots and '.count($windows).' moments of change').'; '.($speech ? 'speech transcribed with word times' : 'no speech found').'. Not every frame; the audio is described from the transcript, not listened to.'];
        $study['treatment'] = $this->treatment($file, $samples);
        $study['moments_status'] = 'skipped';
        if (config('create.mode') !== 'fixture' && (string) config('services.anthropic.key') !== '' && $sheets) {
            $found = $this->moments($study, $file, $sha, $work);
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

    /**
     * Every distinct look: decode every frame small and grey, and start a new look whenever a frame differs from the
     * last kept one by more than LOOK_THRESHOLD. Returns the total frame count and [[start seconds, seconds held], ...].
     */
    private function looks(string $file, float $fps, float $threshold = self::LOOK_THRESHOLD): ?array
    {
        $w = 160; $h = 90;
        $r = Process::timeout(600)->run(['ffmpeg', '-v', 'error', '-i', $file, '-an', '-vf', "scale={$w}:{$h},format=gray", '-f', 'rawvideo', '-']);
        $raw = $r->output(); $size = $w * $h; $n = intdiv(strlen($raw), $size);
        if (! $r->successful() || $n === 0 || $fps <= 0) return null;
        $frame = fn (int $i) => array_values(unpack('C*', substr($raw, $i * $size, $size)));
        $grid = fn (array $px) => array_values(array_filter($px, fn ($k) => $k % 4 === 0, ARRAY_FILTER_USE_KEY));
        $kept = $grid($frame(0)); $starts = [0];
        for ($i = 1; $i < $n; $i++) {
            $cur = $grid($frame($i)); $sum = 0;
            foreach ($cur as $k => $v) $sum += abs($v - $kept[$k]);
            if ($sum / (count($cur) * 255) > $threshold) { $starts[] = $i; $kept = $cur; }
        }
        $looks = [];
        foreach ($starts as $k => $i) $looks[] = [round($i / $fps, 3), round((($starts[$k + 1] ?? $n) - $i) / $fps, 3)];
        return ['frames' => $n, 'looks' => $looks];
    }

    /** One frame per look, from the middle of the look (a held screen is seen settled, a passing one as it passes). */
    public static function lookSamples(array $looks, float $duration, float $fps): array
    {
        $step = $fps > 0 ? 1 / $fps : 0.01;
        $pick = [];
        foreach ($looks['looks'] as [$start, $held]) {
            $t = round(min(max(0.0, $start + $held / 2), max(0.0, $duration - $step)), 3);
            if (! $pick || $t - end($pick) >= $step * 0.5) $pick[] = $t;
        }
        return $pick;
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

    /** The words being spoken around a moment (within 0.6 s), for the label under its frame. */
    public static function wordsAt(?array $speech, float $t): string
    {
        $near = array_filter($speech['words'] ?? [], fn ($w) => $w[2] >= $t - 0.6 && $w[1] <= $t + 0.6);
        return mb_substr(implode(' ', array_column($near, 0)), 0, 42);
    }

    /**
     * Sheets of twenty frames (five across), stored for the planner; each sheet records the seconds of its cells.
     * Under each frame: its time and the words being spoken then, so picture and speech are read together.
     */
    private function sheets(string $file, array $samples, string $sha, string $work, ?array $speech = null, string $name = 'sheet'): array
    {
        $font = collect(['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/lato/Lato-Medium.ttf'])->first(fn ($f) => is_file($f));
        $out = [];
        foreach (array_chunk($samples, self::PER_SHEET) as $k => $times) {
            foreach (glob($work.'/c-*.jpg') ?: [] as $f) @unlink($f);
            $labels = [];
            foreach ($times as $i => $t) {
                $words = self::wordsAt($speech, (float) $t);
                $labels[] = $words;
                $vf = 'scale=384:216:force_original_aspect_ratio=decrease,pad=384:216:(ow-iw)/2:(oh-ih)/2:black';
                if ($font) {
                    file_put_contents($work.'/label.txt', number_format((float) $t, 2).'s'.($words !== '' ? '  '.$words : ''));
                    $vf .= ",pad=384:244:0:0:black,drawtext=fontfile={$font}:textfile={$work}/label.txt:x=6:y=222:fontsize=15:fontcolor=white";
                }
                Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-ss', (string) $t, '-i', $file, '-frames:v', '1', '-vf', $vf, '-q:v', '4', sprintf('%s/c-%02d.jpg', $work, $i)]);
            }
            $rows = (int) ceil(count($times) / 5);
            $r = Process::timeout(60)->run(['ffmpeg', '-v', 'error', '-y', '-framerate', '1', '-i', $work.'/c-%02d.jpg', '-vf', "tile=5x{$rows}:padding=2:color=black", '-frames:v', '1', '-q:v', '4', $work.'/sheet.jpg']);
            if (! $r->successful() || ! is_file($work.'/sheet.jpg')) continue;
            $path = 'create/reference-studies/'.$sha.'/'.$name.'-'.($k + 1).'.jpg';
            Storage::disk('local')->put($path, (string) file_get_contents($work.'/sheet.jpg'));
            $out[] = ['path' => $path, 'times' => $times, 'labels' => $labels, 'labelled' => (bool) $font];
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

    /** Loudness of the low band (kick, bass) every 10 ms, in dB: the pulse a beat grid is read from. */
    private function lowBand(string $file): array
    {
        $r = Process::timeout(120)->run(['ffmpeg', '-hide_banner', '-nostats', '-i', $file, '-vn', '-af', 'aresample=16000,lowpass=f=150,asetnsamples=n=160:p=0,astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level', '-f', 'null', '-']);
        $out = [];
        foreach (preg_split('/\R/', $r->errorOutput()) as $line) if (preg_match('/RMS_level=(-?[\d.]+|-inf)/', $line, $m)) $out[] = $m[1] === '-inf' ? -90.0 : max(-90.0, (float) $m[1]);
        return $out;
    }

    /**
     * The reference's music pulse from 10 ms low-band levels: tempo, beat times and how often its cuts land on a beat.
     * Read from the rises in loudness (onsets) by autocorrelation between 60 and 180 beats a minute.
     */
    public static function beatMap(array $db, array $cuts): array
    {
        $n = count($db);
        if ($n < 400) return ['present' => false];
        $onset = [0.0];
        for ($i = 1; $i < $n; $i++) $onset[] = max(0.0, $db[$i] - $db[$i - 1]);
        $mean = array_sum($onset) / $n;
        $c = array_map(fn ($v) => $v - $mean, $onset);
        $r0 = array_sum(array_map(fn ($v) => $v * $v, $c)) ?: 1.0;
        $rs = [];
        for ($lag = 33; $lag <= 100; $lag++) {
            $r = 0.0;
            for ($i = 0; $i + $lag < $n; $i++) $r += $c[$i] * $c[$i + $lag];
            $rs[$lag] = $r / (($n - $lag) / $n);
        }
        arsort($rs); $best = (int) array_key_first($rs); $bestR = $rs[$best];
        // How clearly one tempo stands out: against the strongest rival that is not the same pulse (or its half or double).
        $rival = collect($rs)->filter(fn ($r, $lag) => abs($lag - $best) > 3 && abs($lag - 2 * $best) > 3 && abs(2 * $lag - $best) > 3)->max() ?? 0.0;
        $confidence = round(max(0.0, $bestR / $r0), 3);
        $prominence = $rival > 0 ? round($bestR / $rival, 2) : 9.99;
        // Sparse beats leave quiet gaps, so presence is judged on the loud end (90th percentile), not the median.
        $sorted = $db; sort($sorted); $level = $sorted[(int) floor($n * 0.9)];
        if ($level < -50 || ! ($confidence >= 0.2 || ($confidence >= 0.08 && $prominence >= 1.5))) return ['present' => false, 'confidence' => $confidence, 'prominence' => $prominence];
        $phase = 0; $phaseScore = -INF;
        for ($p = 0; $p < $best; $p++) { $sum = 0.0; for ($i = $p; $i < $n; $i += $best) $sum += $onset[$i]; if ($sum > $phaseScore) { $phaseScore = $sum; $phase = $p; } }
        $beats = [];
        for ($i = $phase; $i < $n; $i += $best) $beats[] = round($i / 100, 2);
        $onBeat = $cuts ? count(array_filter($cuts, fn ($t) => min(array_map(fn ($b) => abs($b - $t), $beats)) <= 0.08)) / count($cuts) : null;
        return ['present' => true, 'tempo_bpm' => round(6000 / $best, 1), 'beat_seconds' => round($best / 100, 2), 'confidence' => $confidence, 'prominence' => $prominence,
            'beats' => array_slice($beats, 0, 120), 'cuts_on_beat' => $onBeat === null ? null : round($onBeat, 2)];
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
    private function moments(array $study, ?string $file = null, ?string $sha = null, ?string $work = null): array
    {
        $content = [];
        $every = in_array($study['coverage_mode'] ?? 'standard', ['high', 'maximum', 'every_look'], true);
        // The API reads at most 100 images in one request; every_look sends them all up to that limit.
        foreach (array_slice($study['sheets'], 0, $every ? 90 : 4) as $i => $sheet) {
            $bytes = Storage::disk('local')->get($sheet['path']);
            if (! is_string($bytes) || $bytes === '') continue;
            $content[] = ['type' => 'text', 'text' => 'Sheet '.($i + 1).': cells left to right, then down, at seconds '.json_encode($sheet['times']).(! empty($sheet['labelled']) ? '. Under each frame: its time and the words being spoken then.' : '')];
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
            .'"transition_in": "cut|wipe|zoom|fade|whip|none|unknown", "spoken": "words said during it or empty", '
            .'"purpose": "what it does for the viewer, under 12 words (sets up the promise, proves a claim, adds a beat of fun, hands attention to the next step)", "system": "id of the recurring system it belongs to, or empty", "move": "the move that reproduces it, from the list below, or empty", "method": "how it was made: '.implode('|', self::METHODS).'"}], '
            .'"systems": [{"id": "s1", "name": "short name (step card, UI panel, caption, emphasis word, sticker)", "look": "how it looks: layout, type, colour, size, under 30 words", '
            .'"entry": "how it arrives", "active": "what it does while on screen", "hold": "how long it stays and why", "exit": "how it leaves", "move": "the move that reproduces how it arrives or transitions, from the list below", "method": "how it was made: '.implode('|', self::METHODS).'"}], "video_type": "'.implode('|', self::VIDEO_TYPES).'", '
            .'"patterns": {"text_reveal": "how text appears relative to the voice", "emphasis": "how key words are emphasised", "pacing": "rhythm of holds and changes", "signature": "the move it is remembered for"}}.'
            .' A system is an element that recurs with the same look and behaviour (every Step card, every UI panel, the caption style): describe it once in systems and point each of its moments at it.'
            .' Method: motion_graphics is designed type, shapes and UI animated in software; render_3d is a 3D-rendered object or character (clay, toy-like, CG product), whatever its shading (dithered, toon); generated_video is footage from an AI video model (organic motion, morphing details, unstable text); footage is real camera video; presenter is a person talking to camera (real or AI); screen_recording is a real app or site captured; stock is licensed footage or photos; still is a single image held or moved; audiogram is audio shown as a waveform.'
            .' Name each system\'s and each moment\'s move from this list, choosing what the frames show, not the nearest word: '.\App\Services\Create\MotionMoves::prompt().'.'
            .($every ? ' The sheets show one frame for every distinct look, so consecutive cells are the stages of each move: describe each move from its stages (what enters, from where, how it eases, what it becomes). List as many moments as the video has.' : ' At most 30 moments.')];
        // Maximum looks twice: the first reading lists what it could not tell; those stretches are then read frame by frame.
        $maximum = $file !== null && in_array($study['coverage_mode'] ?? '', ['maximum', 'every_look'], true);
        if ($maximum) $content[count($content) - 1]['text'] .= ' Also list open_questions: up to 4 short stretches (each under 2.5 s) where a move is too fast to read from these frames, as [{"start": seconds, "end": seconds, "question": "what you could not tell"}]; [] if none.';
        $model = str_starts_with((string) config('create.agent_model'), 'claude-') ? (string) config('create.agent_model') : 'claude-opus-5-5';
        $first = $this->ask($model, $content, $every);
        if (! $first) return [];
        [$json, $u] = $first;
        $cost = self::cost($u); $passes = 1; $questions = []; $closeups = []; $images = count(array_filter($content, fn ($c) => $c['type'] === 'image'));
        if ($maximum) {
            $questions = collect((array) ($json['open_questions'] ?? []))->filter(fn ($q) => is_array($q) && is_numeric($q['start'] ?? null) && is_numeric($q['end'] ?? null))
                ->map(fn ($q) => ['start' => round(max(0.0, (float) $q['start']), 2), 'end' => round(min((float) $study['duration_seconds'], max((float) $q['start'] + 0.2, min((float) $q['end'], (float) $q['start'] + 2.5))), 2),
                    'question' => mb_substr(trim((string) ($q['question'] ?? '')), 0, 160)])->take(4)->values()->all();
            $second = [['type' => 'text', 'text' => 'Your first reading of this reference, as JSON: '.json_encode(array_intersect_key($json, array_flip(['summary', 'moments', 'systems', 'patterns'])))]];
            foreach ($questions as $k => $q) {
                $fps = (float) (($study['fps'] ?? 0) ?: 30); $frames = [];
                for ($t = $q['start']; $t <= $q['end'] + 1e-6; $t += 1 / $fps) $frames[] = round($t, 3);
                $step = max(1, (int) ceil(count($frames) / 20));
                $pick = array_values(array_filter($frames, fn ($i) => $i % $step === 0, ARRAY_FILTER_USE_KEY));
                $sheet = $this->sheets($file, $pick, (string) $sha, (string) $work, $study['speech'] ?? null, 'closeup-'.($k + 1))[0] ?? null;
                $bytes = $sheet ? Storage::disk('local')->get($sheet['path']) : null;
                if (! is_string($bytes) || $bytes === '') continue;
                $closeups[] = $sheet['path'];
                $second[] = ['type' => 'text', 'text' => 'Close-up of '.$q['start'].' to '.$q['end'].' s, consecutive frames left to right then down, each labelled with its time and the words being spoken. Your question: '.$q['question']];
                $second[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($bytes)]];
            }
            if ($closeups) {
                $second[] = ['type' => 'text', 'text' => 'Answer each question from these frame-by-frame close-ups, then reply with the complete corrected reading as JSON in exactly the same shape (summary, moments with purpose and system, systems, patterns). Keep everything the close-ups do not change.'];
                $again = $this->ask($model, $second, true);
                if ($again && ! empty($again[0]['moments']) && is_array($again[0]['moments'])) { $json = $again[0]; $passes = 2; }
                if ($again) { $cost += self::cost($again[1]); $images += count($closeups); }
            }
        }
        $systems = self::normalizeSystems((array) ($json['systems'] ?? []));
        $videoType = in_array($json['video_type'] ?? null, self::VIDEO_TYPES, true) ? $json['video_type'] : null;
        return ['moments' => self::normalizeMoments((array) ($json['moments'] ?? []), (float) $study['duration_seconds'], $every ? null : 30, array_column($systems, 'id')),
            'systems' => $systems, 'video_type' => $videoType, 'passes' => $passes, 'open_questions' => $questions, 'closeup_sheets' => $closeups,
            'usage' => ['input_tokens' => $u['input_tokens'] ?? null, 'output_tokens' => $u['output_tokens'] ?? null, 'images' => $images],
            'patterns' => collect(['text_reveal', 'emphasis', 'pacing', 'signature'])->mapWithKeys(fn ($k) => [$k => mb_substr(trim((string) data_get($json, 'patterns.'.$k, '')), 0, 200)])->filter()->all() ?: null,
            'summary' => mb_substr(trim((string) ($json['summary'] ?? '')), 0, 300) ?: null,
            'model' => $model, 'cost_microusd' => $cost];
    }

    /** One reading by the model: the parsed JSON and its usage, or null (logged) when the call or the reply fails. */
    private function ask(string $model, array $content, bool $long, string $effort = 'low'): ?array
    {
        try {
            $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout($long ? 600 : 180)
                ->post('https://api.anthropic.com/v1/messages', ['model' => $model, 'max_tokens' => $long ? 32000 : 8000, 'output_config' => ['effort' => $effort], 'messages' => [['role' => 'user', 'content' => $content]]]);
        } catch (\Throwable) { return null; }
        if (! $r->successful()) { \Illuminate\Support\Facades\Log::warning('Create reference study: moment list failed', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]); return null; }
        $text = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $start = strpos($text, '{'); $end = strrpos($text, '}');
        $json = $start !== false && $end !== false ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        return is_array($json) ? [$json, $r->json('usage', [])] : null;
    }

    private static function cost(array $u): int { return (int) ceil(((int) ($u['input_tokens'] ?? 0)) * 5 + ((int) ($u['output_tokens'] ?? 0)) * 25); }

    /** Recurring systems: ids s1.., at most 12, each described once. */
    public static function normalizeSystems(array $raw): array
    {
        $s = fn ($v, $n) => mb_substr(trim((string) $v), 0, $n);
        $out = [];
        foreach ($raw as $x) {
            if (! is_array($x) || $s($x['name'] ?? '', 60) === '') continue;
            $out[] = ['id' => preg_match('/^s\d{1,2}$/', (string) ($x['id'] ?? '')) ? (string) $x['id'] : 's'.(count($out) + 1), 'name' => $s($x['name'], 60), 'look' => $s($x['look'] ?? '', 200),
                'entry' => $s($x['entry'] ?? '', 120), 'active' => $s($x['active'] ?? '', 120), 'hold' => $s($x['hold'] ?? '', 120), 'exit' => $s($x['exit'] ?? '', 120)]
                + (($m = \App\Services\Create\MotionMoves::valid($x['move'] ?? null)) ? ['move' => $m] : [])
                + (in_array($x['method'] ?? null, self::METHODS, true) ? ['method' => $x['method']] : []);
            if (count($out) >= 12) break;
        }
        return array_values(collect($out)->unique('id')->all());
    }

    public static function normalizeMoments(array $raw, float $duration, ?int $max = 30, array $systemIds = []): array
    {
        $s = fn ($v, $n) => mb_substr(trim((string) $v), 0, $n);
        $kinds = ['hook', 'text', 'stat', 'ui', 'zoom', 'sticker', 'character', 'transition', 'cta', 'logo', 'other'];
        $out = [];
        foreach ($raw as $m) {
            if (! is_array($m) || ! is_numeric($m['start'] ?? null) || ! is_numeric($m['end'] ?? null)) continue;
            $a = round(max(0, min($duration, (float) $m['start'])), 2); $b = round(max($a, min($duration, (float) $m['end'])), 2);
            $out[] = ['id' => 'm'.(count($out) + 1), 'start' => $a, 'end' => $b, 'kind' => in_array($m['kind'] ?? '', $kinds, true) ? $m['kind'] : 'other',
                'on_screen_text' => $s($m['on_screen_text'] ?? '', 160), 'visual' => $s($m['visual'] ?? '', 160), 'motion' => $s($m['motion'] ?? '', 120),
                'transition_in' => $s($m['transition_in'] ?? '', 20), 'spoken' => $s($m['spoken'] ?? '', 200),
                'purpose' => $s($m['purpose'] ?? '', 120), 'system' => in_array($m['system'] ?? '', $systemIds, true) ? $m['system'] : '']
                + (($move = \App\Services\Create\MotionMoves::valid($m['move'] ?? null)) ? ['move' => $move] : [])
                + (in_array($m['method'] ?? null, self::METHODS, true) ? ['method' => $m['method']] : []);
            if ($max !== null && count($out) >= $max) break;
        }
        usort($out, fn ($x, $y) => $x['start'] <=> $y['start']);
        foreach ($out as $i => &$m) $m['id'] = 'm'.($i + 1);
        return $out;
    }
}

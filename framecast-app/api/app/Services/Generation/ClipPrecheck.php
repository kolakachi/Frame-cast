<?php

namespace App\Services\Generation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * A look before every paid clip (owner, 2026-11-05): a small vision model reads the clip's prompt with the frames it
 * starts and ends on (and its reference images) and says what a video model would get wrong, before anything is bought.
 *
 * - Prompt problems (VideoCraft::AVOID, two actions, a camera move with no reason): the prompt is rewritten, checked once
 *   more, and the clip is made from the better of the two. Quoted speech, [ImageN] names and timings never change.
 * - Frame problems (the start and end frames disagree on who or where, a lopsided face, a different camera): reported.
 *   The editor shows them before charging ("redraw or make anyway"); Create and UGC note them and go on.
 *
 * Every check is kept in clip_prechecks. It costs about $0.003 and is not charged. Any failure lets the clip through
 * unchanged: the check never stops a purchase on its own fault.
 */
class ClipPrecheck
{
    private const PROMPT = <<<'TXT'
You check a prompt for an AI video model before the clip is paid for. The images above, when any, are the clip's frames: {ROLES}.

Find what the video model would get wrong:
- prompt_issues: problems in the prompt alone. {RULES}
- frame_issues: problems in the images: a start and end frame that disagree on who is there, their clothes, the place, the product or the camera position; an end frame that does not follow from the start in the clip's length; lopsided or extra features on a face or body; anything in the frames the prompt contradicts. Leave it empty when there are no images.

Report only real problems that would visibly spoil the clip, each in one short sentence a user understands. No problems: empty lists.

When there are prompt_issues, also write "rewrite": the whole prompt with only those problems fixed. Keep everything else word for word: every quoted line exactly, every [Image1]-style name, every timing like 00:00-00:02, the sound and on-screen text rules, and the same length or close to it. Otherwise "rewrite" is null.

The clip is {SECONDS} seconds. Its prompt:
"""
{CLIP}
"""

Answer JSON only: {"prompt_issues": ["..."], "frame_issues": ["..."], "rewrite": "..." | null}
TXT;

    /**
     * Check one clip before it is bought.
     *
     * @param  list<array{bytes: string, mime?: string, role: string}>  $images  role: start | end | reference
     * @param  array{workspace_id?: int|null, ref?: string|null, seconds?: int|null}  $ctx
     * @return array{ok: bool, prompt: string, rewritten: bool, prompt_issues: list<string>, frame_issues: list<string>, id: int|null}
     */
    public function check(string $prompt, array $images, string $source, array $ctx = []): array
    {
        $pass = ['ok' => true, 'prompt' => $prompt, 'rewritten' => false, 'prompt_issues' => [], 'frame_issues' => [], 'id' => null];
        $key = (string) config('services.anthropic.key');
        if (! config('create.clip_precheck', true) || $key === '' || config('create.mode') === 'fixture' || trim($prompt) === '') return $pass;
        if (! empty($ctx['workspace_id']) && empty(\App\Services\Usage\UsageContext::current()['workspace_id'])) \App\Services\Usage\UsageContext::set((int) $ctx['workspace_id']);

        $small = [];
        foreach (array_slice($images, 0, 4) as $img) {
            $jpeg = is_string($img['bytes'] ?? null) && $img['bytes'] !== '' ? self::jpeg($img['bytes']) : null;
            if ($jpeg !== null) $small[] = ['jpeg' => $jpeg, 'role' => (string) ($img['role'] ?? 'reference')];
        }
        $seconds = (int) ($ctx['seconds'] ?? 5);

        $rules = VideoCraft::rules($source === 'ugc' ? 'ugc' : 'plan');
        $first = $this->ask($key, $prompt, $small, $seconds, $rules);
        if ($first === null) return $pass;
        $final = $prompt;
        $issues = $first['prompt_issues'];
        $rewritten = false;
        if ($issues !== [] && ($rewrite = self::keepsFixedParts($prompt, (string) $first['rewrite'])) !== null) {
            // Checked once more; the rewrite is used unless it came out worse.
            $again = $this->ask($key, $rewrite, $small, $seconds, $rules);
            if ($again === null || count($again['prompt_issues']) <= count($issues)) {
                [$final, $rewritten] = [$rewrite, true];
                $issues = $again['prompt_issues'] ?? [];
            }
        }
        $result = ['ok' => $first['frame_issues'] === [] && $issues === [], 'prompt' => $final, 'rewritten' => $rewritten,
            'prompt_issues' => $first['prompt_issues'], 'frame_issues' => $first['frame_issues'], 'id' => null];
        $result['id'] = rescue(fn () => (int) DB::table('clip_prechecks')->insertGetId([
            'source' => mb_substr($source, 0, 32),
            'workspace_id' => $ctx['workspace_id'] ?? null,
            'ref' => isset($ctx['ref']) ? mb_substr((string) $ctx['ref'], 0, 191) : null,
            'verdict' => $first['frame_issues'] !== [] ? 'frames' : ($first['prompt_issues'] !== [] ? ($rewritten ? 'rewritten' : 'prompt') : 'ok'),
            'issues_json' => json_encode(['prompt' => $first['prompt_issues'], 'frames' => $first['frame_issues'], 'after_rewrite' => $rewritten ? $issues : null], JSON_UNESCAPED_UNICODE),
            'images' => count($small),
            'prompt_before' => $prompt,
            'prompt_after' => $rewritten ? $final : null,
            'created_at' => now(),
        ]), null, false);
        if (! $result['ok']) Log::info('clip precheck: problems found', ['source' => $source, 'ref' => $ctx['ref'] ?? null, 'prompt' => $first['prompt_issues'], 'frames' => $first['frame_issues'], 'rewritten' => $rewritten]);

        return $result;
    }

    /** One look by the check model; null when it could not be asked or answered badly. */
    private function ask(string $key, string $prompt, array $small, int $seconds, string $rules): ?array
    {
        $roles = $small === [] ? 'there are none' : implode(', ', array_map(fn ($s, $i) => 'image '.($i + 1).' is the '.match ($s['role']) {
            'start' => 'first frame', 'end' => 'last frame', default => 'reference (who or what must look the same)',
        }, $small, array_keys($small)));
        $content = array_map(fn ($s) => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($s['jpeg'])]], $small);
        $content[] = ['type' => 'text', 'text' => str_replace(['{ROLES}', '{RULES}', '{SECONDS}', '{CLIP}'],
            [$roles, $rules, (string) max(1, $seconds), mb_substr($prompt, 0, 6000)], self::PROMPT)];
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(30)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'),
                    'max_tokens' => 2500, 'messages' => [['role' => 'user', 'content' => $content]]]);
        } catch (\Throwable $e) {
            Log::warning('clip precheck: the call failed', ['error' => $e->getMessage()]);

            return null;
        }
        if (! $r->successful()) return null;

        return self::parse(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''));
    }

    /** The model's answer; null unless it is the JSON asked for. */
    public static function parse(string $text): ?array
    {
        $a = strpos($text, '{'); $b = strrpos($text, '}');
        $json = $a !== false && $b !== false ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
        if (! is_array($json) || (! array_key_exists('prompt_issues', $json) && ! array_key_exists('frame_issues', $json))) return null;
        $list = fn ($v) => array_values(array_slice(array_map(fn ($s) => mb_substr(trim($s), 0, 300), array_filter(is_array($v) ? $v : [], fn ($s) => is_string($s) && trim($s) !== '')), 0, 6));

        return ['prompt_issues' => $list($json['prompt_issues'] ?? null), 'frame_issues' => $list($json['frame_issues'] ?? null),
            'rewrite' => is_string($json['rewrite'] ?? null) ? trim($json['rewrite']) : ''];
    }

    /**
     * The rewrite, when it kept what must never change: every quoted line, every [ImageN] name, every timing, and most
     * of the length. Null otherwise (the clip is then made from the original prompt).
     */
    public static function keepsFixedParts(string $before, string $after): ?string
    {
        if ($after === '' || $after === $before || mb_strlen($after) < mb_strlen($before) * 0.6) return null;
        preg_match_all('/"[^"\n]{3,}"|\[Image\d+\]|\b\d{2}:\d{2}-\d{2}:\d{2}\b/u', $before, $m);
        foreach (array_unique($m[0]) as $fixed) {
            if (! str_contains($after, $fixed)) return null;
        }

        return $after;
    }

    /** The bytes behind an image reference a video job is given (a data URI or a URL); null when it cannot be fetched. */
    public static function bytesOf(?string $ref): ?string
    {
        if ($ref === null || $ref === '') return null;
        if (str_starts_with($ref, 'data:')) {
            $bytes = base64_decode((string) substr($ref, (int) strpos($ref, ',') + 1), true);

            return is_string($bytes) && $bytes !== '' ? $bytes : null;
        }
        if (! preg_match('#^https?://#i', $ref)) return null;
        try {
            $http = Http::timeout(15);
            if (str_starts_with($ref, 'https://api.replicate.com/')) $http = $http->withToken((string) config('services.replicate.api_token'));
            $r = $http->get($ref);

            return $r->successful() && $r->body() !== '' ? $r->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** An image as a JPEG at most 768 px wide, for the check model; null when it cannot be read. */
    public static function jpeg(string $bytes): ?string
    {
        $base = tempnam(sys_get_temp_dir(), 'precheck-');
        $out = $base.'.jpg';
        try {
            file_put_contents($base, $bytes);
            Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-i', $base, '-frames:v', '1', '-vf', "scale='min(768,iw)':-2", '-q:v', '4', $out]);
            $jpeg = is_file($out) ? (string) file_get_contents($out) : '';

            return $jpeg !== '' ? $jpeg : null;
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($out);
            @unlink($base);
        }
    }
}

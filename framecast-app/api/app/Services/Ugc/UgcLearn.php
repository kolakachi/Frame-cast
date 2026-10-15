<?php

namespace App\Services\Ugc;

use App\Services\Media\StorageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/**
 * LEARN (owner, 2026-10-12, built 2026-10-15): an agent that watches every UGC take and keeps a lessons list.
 *
 * review(): after a take finishes or fails, one vision call reads the brief, what each clip was asked for, the checks
 * (face scores, reviewer verdicts, the error) and frames of the final video, and records the pitfalls with the lesson
 * each one teaches (ugc_take_reviews). consolidate(): once a day the recent pitfalls are merged into at most ten
 * lessons, each for the director, the shot planner or the action-sheet writer (ugc_lessons). for(): what each of them
 * reads, appended to its prompt while services.ugc.lessons_apply is on.
 */
class UgcLearn
{
    public const ROLES = ['director', 'planner', 'sheet'];

    private const AREAS = ['identity', 'wardrobe', 'product', 'action', 'speech', 'sound', 'room', 'framing', 'text', 'pacing', 'other'];

    /**
     * Review one take. $take: project_id, workspace_id, ugc_type, engine, outcome (delivered|failed), brief, clips
     * (what each clip was asked), checks (face scores, reviewer verdicts), error, video_url (storage url of the final).
     */
    public function review(array $take): ?array
    {
        $frames = ! empty($take['video_url']) ? $this->frames((string) $take['video_url']) : [];
        $text = "You review a short UGC video ad that an AI pipeline just made, to learn what to do better next time.\n\n"
            .'Type: '.($take['ugc_type'] ?: 'unknown').'. Model: '.($take['engine'] ?: 'unknown').'. Outcome: '.$take['outcome'].".\n"
            .'The brief and script: '.mb_substr((string) ($take['brief'] ?? ''), 0, 1500)."\n"
            .'What each clip was asked for: '.mb_substr(json_encode($take['clips'] ?? []), 0, 4000)."\n"
            .'The pipeline\'s own checks: '.mb_substr(json_encode($take['checks'] ?? []), 0, 1500)."\n"
            .(! empty($take['error']) ? 'It failed with: '.mb_substr((string) $take['error'], 0, 300)."\n" : '')
            .($frames ? 'The images are frames of the finished video, in order.' : 'There is no finished video.')."\n\n"
            ."List only real pitfalls a viewer or the brief's author would notice (a face that changes, an action that never happens, "
            ."the product changing shape, words on screen nobody asked for, a room that changes, a line cut off), and for each the one "
            ."lesson that would have prevented it, written as an instruction to the part of the pipeline that could act on it: the "
            ."director (who pitches the ad), the planner (who splits it into shots) or the sheet writer (who times each clip's actions). "
            ."No pitfall is better than an invented one.\n\n"
            .'Answer JSON only: {"score": 1-10 (how well it delivers the brief), "pitfalls": [{"area": one of '.implode('|', self::AREAS)
            .', "what": "what went wrong, briefly", "role": "director|planner|sheet", "lesson": "one imperative sentence"}]}';
        $answer = $this->ask($text, $frames, 1500);
        if ($answer === null) return null;
        $pitfalls = array_values(array_filter(array_map(fn ($p) => is_array($p) && trim((string) ($p['lesson'] ?? '')) !== '' ? [
            'area' => in_array($p['area'] ?? '', self::AREAS, true) ? $p['area'] : 'other',
            'what' => mb_substr(trim((string) ($p['what'] ?? '')), 0, 300),
            'role' => in_array($p['role'] ?? '', self::ROLES, true) ? $p['role'] : 'director',
            'lesson' => mb_substr(trim((string) $p['lesson']), 0, 300),
        ] : null, (array) ($answer['pitfalls'] ?? []))));
        $score = is_numeric($answer['score'] ?? null) ? max(1, min(10, (int) $answer['score'])) : null;
        DB::table('ugc_take_reviews')->insert([
            'project_id' => (int) $take['project_id'], 'workspace_id' => $take['workspace_id'] ?? null,
            'ugc_type' => $take['ugc_type'] ?: null, 'engine' => $take['engine'] ?: null, 'outcome' => (string) $take['outcome'],
            'score' => $score, 'pitfalls' => json_encode(array_slice($pitfalls, 0, 8)), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['score' => $score, 'pitfalls' => $pitfalls];
    }

    /** Merge the recent pitfalls into at most ten lessons; null when there is nothing new to learn from. */
    public function consolidate(int $reviews = 60): ?array
    {
        $rows = DB::table('ugc_take_reviews')->orderByDesc('id')->limit($reviews)->get(['ugc_type', 'engine', 'pitfalls']);
        $seen = $rows->flatMap(fn ($r) => array_map(fn ($p) => $p + ['type' => $r->ugc_type, 'engine' => $r->engine], json_decode((string) $r->pitfalls, true) ?: []))->values();
        if ($seen->isEmpty()) return null;
        $current = self::current();
        $text = "These are pitfalls found in recent UGC video ads made by an AI pipeline, each with the lesson the reviewer drew:\n"
            .mb_substr(json_encode($seen->all()), 0, 20000)."\n\n"
            .($current ? 'The lessons in use now: '.json_encode($current)."\n\n" : '')
            ."Write the lessons list the pipeline will follow from now on: at most ten, each one imperative sentence for one role "
            ."(director, planner or sheet), most frequent and most costly first. Keep a lesson only when the pitfall recurs (seen in two "
            ."or more ads) or is severe; merge duplicates; drop lessons in use that no recent pitfall supports; never a lesson about one "
            ."product or brand. Answer JSON only: {\"lessons\": [{\"role\": \"director|planner|sheet\", \"lesson\": \"...\", \"seen\": number of ads}]}";
        $answer = $this->ask($text, [], 2000);
        if ($answer === null) return null;
        $lessons = array_values(array_filter(array_map(fn ($l) => is_array($l) && in_array($l['role'] ?? '', self::ROLES, true) && trim((string) ($l['lesson'] ?? '')) !== ''
            ? ['role' => $l['role'], 'lesson' => mb_substr(trim((string) $l['lesson']), 0, 300), 'seen' => (int) ($l['seen'] ?? 0)] : null, (array) ($answer['lessons'] ?? []))));
        $lessons = array_slice($lessons, 0, 10);
        DB::table('ugc_lessons')->insert(['lessons' => json_encode($lessons), 'reviews_read' => $rows->count(), 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('ugc-lessons');

        return $lessons;
    }

    /** @return list<array{role: string, lesson: string, seen: int}> */
    public static function current(): array
    {
        return Cache::remember('ugc-lessons', 600, function () {
            if (! Schema::hasTable('ugc_lessons')) return [];
            return json_decode((string) DB::table('ugc_lessons')->orderByDesc('id')->value('lessons'), true) ?: [];
        });
    }

    /** The lessons one role reads, as a paragraph for its prompt; empty when off or when there are none. */
    public static function for(string $role): string
    {
        if (! config('services.ugc.lessons_apply', true)) return '';
        $mine = array_values(array_filter(self::current(), fn ($l) => ($l['role'] ?? '') === $role));
        if ($mine === []) return '';

        return "\n\nLessons from recent ads made this way (follow them):\n".implode("\n", array_map(fn ($l) => '- '.$l['lesson'], $mine));
    }

    /** Six frames across the finished video, small. */
    private function frames(string $storageUrl): array
    {
        $bytes = app(StorageService::class)->get($storageUrl);
        if (! is_string($bytes) || $bytes === '') return [];
        $video = tempnam(sys_get_temp_dir(), 'learn-').'.mp4';
        file_put_contents($video, $bytes);
        $out = [];
        try {
            $len = (float) trim(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $video])->output());
            if ($len <= 0) return [];
            foreach ([0.08, 0.25, 0.42, 0.58, 0.75, 0.92] as $at) {
                $file = tempnam(sys_get_temp_dir(), 'learn-').'.jpg';
                Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-ss', sprintf('%.2f', $len * $at), '-i', $video, '-frames:v', '1', '-vf', 'scale=480:-2', '-q:v', '5', $file]);
                if (is_file($file) && filesize($file)) $out[] = (string) file_get_contents($file);
                @unlink($file);
            }
        } finally {
            @unlink($video);
        }
        return $out;
    }

    private function ask(string $text, array $jpegs, int $maxTokens): ?array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') return null;
        $content = array_map(fn ($j) => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($j)]], $jpegs);
        $content[] = ['type' => 'text', 'text' => $text];
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(120)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('services.ai.direct_model', 'claude-sonnet-5-5'),
                    'max_tokens' => $maxTokens, 'messages' => [['role' => 'user', 'content' => $content]]]);
            if (! $r->successful()) {
                Log::warning('ugc learn: the call failed', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 200)]);
                return null;
            }
            $answer = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            return UgcPlan::decodeModelJson($answer);
        } catch (\Throwable $e) {
            Log::warning('ugc learn: could not run', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }
    }
}

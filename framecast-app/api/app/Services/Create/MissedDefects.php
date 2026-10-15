<?php

namespace App\Services\Create;

use App\Jobs\ReviewMissedDefectJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * RD-14 (the Opus Motion Design Harness, 2026-10-15): a defect the checks missed becomes a check, approved once.
 *
 * capture(): a message on a conversation that already has a delivered video is read by one small model call (queued):
 * does it report a fault in that video (text cut off, a word mispronounced, a logo missing), as opposed to a new wish
 * (make it blue)? A fault is recorded with a proposed check: one thing a reviewer can see on the frames. Approved
 * checks (create:missed-defects --approve) go to the visual reviewer of every build as house checks.
 */
class MissedDefects
{
    public const AREAS = ['text', 'layout', 'motion', 'audio', 'timing', 'brand', 'other'];

    public const LIMIT = 20;

    public static function capture(object $conversation, string $content): void
    {
        if (! config('create.missed_defects', true) || empty($conversation->head_revision_id)) return;
        if (mb_strlen(trim($content)) < 12 || StepNudge::isGoOn($content)) return;
        ReviewMissedDefectJob::dispatch((int) $conversation->workspace_id, (string) $conversation->id, (string) $conversation->head_revision_id, mb_substr($content, 0, 2000));
    }

    /** Read one message against the version it is about; records a defect when it reports one. */
    public function review(int $workspaceId, string $conversationId, string $revisionId, string $note): ?array
    {
        $rev = DB::table('composition_revisions')->where('id', $revisionId)->first(['summary', 'metadata_json']);
        if (! $rev) return null;
        $checks = json_decode((string) $rev->metadata_json, true)['final_checks'] ?? null;
        $verdict = $this->ask("A user is replying to a short video an AI made for them. Decide whether their message reports a FAULT in the delivered video that an automatic check should have caught (text cut off or unreadable, overlapping elements, a word misspelt or mispronounced, audio out of sync or too loud, a required item missing, a glitch, a frame flash, a logo wrong), or only asks for a change of taste or content (another colour, new copy, a different style, longer, add something new). Only a fault counts.\n\n"
            .'Their message: '.$note."\n\n"
            .'What the video was said to contain: '.mb_substr((string) $rev->summary, 0, 800)."\n"
            .'The automatic checks that ran on it: '.mb_substr(json_encode($checks), 0, 1200)."\n\n"
            .'Reply with JSON only: {"fault": true|false, "defect": "what was wrong, under 12 words", "area": "'.implode('|', self::AREAS).'", '
            .'"check": "one general check a reviewer can verify by looking at frames of ANY future video, under 25 words, not about this video\'s content (e.g. Every line of on-screen text is fully inside the frame and not clipped by its box)"}');
        if (! is_array($verdict) || empty($verdict['fault']) || trim((string) ($verdict['check'] ?? '')) === '') return null;
        $row = ['workspace_id' => $workspaceId, 'conversation_id' => $conversationId, 'revision_id' => $revisionId, 'note' => $note,
            'defect' => mb_substr(trim((string) ($verdict['defect'] ?? 'unspecified')), 0, 160),
            'area' => in_array($verdict['area'] ?? '', self::AREAS, true) ? $verdict['area'] : 'other',
            'proposed_check' => mb_substr(trim((string) $verdict['check']), 0, 300), 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()];
        DB::table('create_missed_defects')->insert($row);
        return $row;
    }

    /** The approved checks every build's visual reviewer runs, newest first. */
    public static function approved(): array
    {
        if (! Schema::hasTable('create_missed_defects')) return [];
        return Cache::remember('create:house-checks', 300, fn () => DB::table('create_missed_defects')->where('status', 'approved')
            ->orderByDesc('updated_at')->limit(self::LIMIT)->pluck('proposed_check')->unique()->values()->all());
    }

    public static function decide(int $id, string $status, ?string $check = null): bool
    {
        $done = DB::table('create_missed_defects')->where('id', $id)->update(array_filter(['status' => $status, 'proposed_check' => $check, 'updated_at' => now()], fn ($v) => $v !== null)) > 0;
        Cache::forget('create:house-checks');
        return $done;
    }

    private function ask(string $text): ?array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') return null;
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(60)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.missed_defect_model', 'claude-haiku-5-5'),
                    'max_tokens' => 400, 'messages' => [['role' => 'user', 'content' => $text]]]);
            if (! $r->successful()) {
                Log::warning('missed defects: the call failed', ['status' => $r->status()]);
                return null;
            }
            return \App\Services\Ugc\UgcPlan::decodeModelJson(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''));
        } catch (\Throwable $e) {
            Log::warning('missed defects: could not run', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }
    }
}

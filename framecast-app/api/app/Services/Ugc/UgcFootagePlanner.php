<?php

namespace App\Services\Ugc;

use App\Models\FootageSession;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Turns a corrected source read plus the user's brief into a target plan:
 * per passage, what stays, what is re-performed, what is rebuilt — and why.
 * Each planned passage carries a full UGC segment, so producing the plan is
 * the same machinery as any other UGC take.
 */
class UgcFootagePlanner
{
    public const TREATMENTS = ['new', 'rebuilt', 'reused'];

    public function __construct(private readonly AIGenerationAdapter $ai) {}

    /**
     * @return array{summary: array, passages: array, needs: array}
     */
    public function plan(FootageSession $session): array
    {
        $read = $session->correctedRead();
        $kept = array_values(array_filter($read['passages'], fn ($p) => empty($p['dropped'])));
        if ($kept === []) {
            throw ValidationException::withMessages([
                'plan' => 'Every passage was excluded — there is nothing left to plan from.',
            ]);
        }

        try {
            $result = $this->ai->generate('ugc_footage_target_plan', [
                'brief' => trim((string) $session->brief) ?: 'not stated — keep the structure, change nothing else',
                'rights' => $session->rights === 'reuse'
                    ? 'The user owns this footage and may reuse it directly. Prefer reusing a passage over re-performing it when the change does not touch it.'
                    : 'Reference only: none of the source footage, faces or audio may be reused. Every passage is re-performed or rebuilt.',
                'source_read' => json_encode([
                    'duration' => $read['duration'],
                    'speakers' => $read['speakers'],
                    'passages' => array_map(fn ($p) => [
                        'id' => $p['id'], 'title' => $p['title'], 'kind' => $p['kind'],
                        'transcript' => $p['transcript'], 'note' => $p['note'],
                        'seconds' => round(max(1, ($p['end'] ?? 0) - ($p['start'] ?? 0)), 1),
                        'important' => ! empty($p['important']),
                    ], $kept),
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            // Twelve passages of full segments is a lot of JSON; a cap that
            // truncates mid-object reads back as 'Syntax error'.
            ], 8000, 0.3, ['operation' => 'ugc_footage_target_plan']);

            $parsed = UgcPlan::decodeModelJson((string) ($result['content'] ?? $result['text'] ?? ''));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Footage target plan failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            throw ValidationException::withMessages([
                'plan' => 'Could not plan your version just now. Nothing was charged — try again.',
            ]);
        }

        $passages = $this->passages((array) ($parsed['passages'] ?? []), $kept, $session->rights);
        $needs = $this->needs((array) ($parsed['needs'] ?? []), $passages, $kept);

        $counts = array_count_values(array_column($passages, 'treatment'));

        return [
            'summary' => [
                'reused' => $counts['reused'] ?? 0,
                'performed' => $counts['new'] ?? 0,
                'rebuilt' => $counts['rebuilt'] ?? 0,
                'total' => count($passages),
            ],
            'passages' => $passages,
            'needs' => $needs,
        ];
    }

    /** The planned passages, validated as real UGC segments. */
    private function passages(array $raw, array $kept, string $rights): array
    {
        $byId = array_column($kept, null, 'id');
        $out = [];
        foreach (array_slice($raw, 0, 12) as $p) {
            if (! is_array($p) || empty($p['id']) || ! isset($byId[$p['id']])) {
                continue;
            }
            $treatment = in_array($p['treatment'] ?? '', self::TREATMENTS, true) ? $p['treatment'] : 'new';
            // Reference-only rights can never reuse the source outright.
            if ($treatment === 'reused' && $rights !== 'reuse') {
                $treatment = 'new';
            }
            $seg = (array) ($p['segment'] ?? []);
            // A card with neither words nor a headline fails validation and
            // renders as nothing. A silent source (music-only reels) tends to
            // produce exactly that, so the passage's own target stands in.
            if (($seg['kind'] ?? 'b_roll') === 'b_roll'
                && trim((string) ($seg['script_text'] ?? '')) === ''
                && trim((string) ($seg['headline'] ?? '')) === '') {
                $seg['headline'] = mb_substr(trim((string) ($p['target'] ?? $byId[$p['id']]['title'])), 0, 80);
            }
            $out[] = [
                'id' => (string) $p['id'],
                'title' => $byId[$p['id']]['title'],
                'treatment' => $treatment,
                'source' => mb_substr(trim((string) ($p['source'] ?? $byId[$p['id']]['transcript'])), 0, 500),
                'target' => mb_substr(trim((string) ($p['target'] ?? '')), 0, 500),
                'reason' => mb_substr(trim((string) ($p['reason'] ?? '')), 0, 500),
                'segment' => [
                    'kind' => in_array($seg['kind'] ?? '', ['on_camera', 'b_roll', 'reaction'], true) ? $seg['kind'] : 'b_roll',
                    'script_text' => mb_substr(trim((string) ($seg['script_text'] ?? '')), 0, 1500),
                    'seconds' => max(1, min(60, (float) ($seg['seconds'] ?? 5))),
                    'visual_brief' => mb_substr(trim((string) ($seg['visual_brief'] ?? '')), 0, 1000)
                        ?: 'Match the source framing for this passage.',
                    'voice_direction' => mb_substr(trim((string) ($seg['voice_direction'] ?? '')), 0, 500),
                    'motion_prompt' => mb_substr(trim((string) ($seg['motion_prompt'] ?? '')), 0, 1000),
                    'headline' => mb_substr(trim((string) ($seg['headline'] ?? '')), 0, 180),
                    'anchor' => mb_substr(trim((string) ($seg['anchor'] ?? $seg['script_text'] ?? '')), 0, 1500),
                    'anchor_role' => in_array($seg['anchor_role'] ?? '', UgcPlan::ANCHOR_ROLES, true) ? $seg['anchor_role'] : 'illustrate',
                    'source' => ($seg['kind'] ?? '') === 'b_roll'
                        ? (in_array($seg['source'] ?? '', ['upload', 'stock', 'generate'], true) ? $seg['source'] : 'generate')
                        : null,
                    'asset_id' => null, // assigned at produce time — the model never invents ids
                ],
            ];
        }

        if ($out === []) {
            throw ValidationException::withMessages([
                'plan' => 'The plan came back empty. Try again, or adjust the brief.',
            ]);
        }

        return $out;
    }

    /** What we still need from the user before production can start. */
    private function needs(array $raw, array $passages, array $kept): array
    {
        $out = [];
        foreach (array_slice($raw, 0, 8) as $i => $n) {
            if (! is_array($n) || trim((string) ($n['label'] ?? '')) === '') {
                continue;
            }
            $out[] = [
                'key' => 'need'.($i + 1),
                'label' => mb_substr(trim((string) $n['label']), 0, 120),
                'why' => mb_substr(trim((string) ($n['why'] ?? '')), 0, 300),
                'type' => in_array($n['type'] ?? '', ['text', 'asset', 'presenter'], true) ? $n['type'] : 'text',
            ];
        }

        // Structural needs the model may not name: a presenter whenever any
        // passage is performed, and an answer for every unclear passage.
        $hasPerformed = (bool) array_filter($passages, fn ($p) => $p['segment']['kind'] !== 'b_roll');
        if ($hasPerformed && ! array_filter($out, fn ($n) => $n['type'] === 'presenter')) {
            $out[] = ['key' => 'presenter', 'label' => 'Presenter for the new performance',
                'why' => 'The source performance cannot be reused, so someone has to give the new one.', 'type' => 'presenter'];
        }
        foreach ($kept as $p) {
            if ($p['kind'] === 'unclear' && empty($p['important_answered'])) {
                $out[] = ['key' => 'answer_'.$p['id'], 'label' => 'Exact wording for "'.$p['title'].'"',
                    'why' => mb_substr((string) $p['note'], 0, 200) ?: 'We could not read this from the source, and we never guess offer text.', 'type' => 'text'];
            }
        }

        return $out;
    }
}

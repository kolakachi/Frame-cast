<?php

namespace App\Services\Ugc;

use App\Models\Asset;
use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Media\MediaTranscriptionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Reads an existing ad into an account of what each beat is doing, so the same
 * shape can carry a different product.
 *
 * Not a copy. The question "for UGC ads, do we go frame by frame?" has an
 * emphatic no for an answer: reproducing someone else's footage is both a
 * rights problem and a brittle one, because the moment the new script says
 * anything different the copied frames stop fitting. What survives a rewrite
 * is the argument — hook, then the cost of the problem, then proof — and that
 * is what gets recorded here. The beats become anchors for the plan, which is
 * what UgcPlan's anchor_role vocabulary was built for.
 */
class UgcReference
{
    public const ROLES = ['hook', 'problem', 'proof', 'demonstration', 'contrast', 'cta'];

    public function __construct(
        private readonly AIGenerationAdapter $ai,
        private readonly MediaTranscriptionService $transcription,
    ) {}

    /**
     * @return array{duration: float, shape: string, beats: array<int, array<string, mixed>>}
     */
    public function read(Asset $asset): array
    {
        $timed = $this->transcription->transcribeAssetWithTimestamps($asset);
        $segments = array_values(array_filter((array) ($timed['segments'] ?? [])));

        if ($segments === []) {
            // Silent or music-only. Structure could still be read from frames,
            // which this does not do yet — say so rather than returning an
            // invented shape.
            throw ValidationException::withMessages([
                'reference' => 'No speech was found in that video, so there is nothing to read its structure from yet. Upload one with a voiceover, or describe the ad you have in mind instead.',
            ]);
        }

        try {
            $result = $this->ai->generate('ugc_reference_read', [
                'duration' => (string) round((float) ($asset->duration_seconds ?? 0), 1),
                'transcript_json' => json_encode(
                    array_map(fn ($s) => [
                        'start' => round((float) ($s['start'] ?? 0), 2),
                        'end' => round((float) ($s['end'] ?? 0), 2),
                        'text' => (string) ($s['text'] ?? ''),
                    ], $segments),
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ),
            ], 2500, 0.2, ['operation' => 'ugc_reference_read']);

            $content = trim((string) ($result['content'] ?? $result['text'] ?? ''));
            $content = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $content);
            $parsed = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::warning('UGC reference read produced nothing usable', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            throw ValidationException::withMessages([
                'reference' => 'Could not read that video just now. No credits were spent — try again, or plan the ad from a brief instead.',
            ]);
        }

        return [
            'duration' => round((float) ($parsed['duration'] ?? $asset->duration_seconds ?? 0), 1),
            'shape' => mb_substr(trim((string) ($parsed['shape'] ?? '')), 0, 300),
            'beats' => $this->beats((array) ($parsed['beats'] ?? [])),
        ];
    }

    /**
     * Only the fields we asked for, only the roles we named, and nothing that
     * could carry the original's brand across into the new ad.
     *
     * @param  array<int, mixed>  $raw
     * @return array<int, array<string, mixed>>
     */
    private function beats(array $raw): array
    {
        $beats = [];
        foreach (array_slice($raw, 0, 8) as $b) {
            if (! is_array($b)) {
                continue;
            }
            $role = (string) ($b['role'] ?? '');
            $beats[] = [
                'start' => round((float) ($b['start'] ?? 0), 2),
                'end' => round((float) ($b['end'] ?? 0), 2),
                'role' => in_array($role, self::ROLES, true) ? $role : 'hook',
                'does' => mb_substr(trim((string) ($b['does'] ?? '')), 0, 300),
                'on_screen' => mb_substr(trim((string) ($b['on_screen'] ?? '')), 0, 300),
                // Kept for the planner to see the pacing, never to reuse: the
                // director is told separately that the words are the original's.
                'spoken' => mb_substr(trim((string) ($b['spoken'] ?? '')), 0, 600),
            ];
        }

        if ($beats === []) {
            throw ValidationException::withMessages([
                'reference' => 'That video did not read as an ad with a clear structure. Plan from a brief instead.',
            ]);
        }

        return $beats;
    }
}

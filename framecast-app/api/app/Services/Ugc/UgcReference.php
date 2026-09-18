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
        private readonly ?UgcFrameSampler $frames = null,
    ) {}

    /**
     * @return array{duration: float, shape: string, beats: array<int, array<string, mixed>>}
     */
    public function read(Asset $asset): array
    {
        $duration = (float) ($asset->duration_seconds ?? 0);
        // Pictures first: they are what a transcript cannot give us, and they
        // are the only thing a silent ad has.
        $frames = $this->frames?->sample($asset, $duration) ?? [];

        $segments = [];
        try {
            $timed = $this->transcription->transcribeAssetWithTimestamps($asset);
            $segments = array_values(array_filter((array) ($timed['segments'] ?? [])));
            if ($segments === [] && ! empty($timed['words'])) {
                // Word granularity comes back with no segments at all; the
                // words carry the whole script.
                $segments = UgcPlan::segmentsFromWords((array) $timed['words']);
            }
        } catch (\Throwable $e) {
            // A silent reference fails transcription rather than returning
            // nothing. With frames in hand that is survivable.
            Log::info('UGC reference has no usable transcript', ['error' => mb_substr($e->getMessage(), 0, 120)]);
        }

        if ($segments === [] && $frames === []) {
            throw ValidationException::withMessages([
                'reference' => 'Nothing could be read from that file — no speech, and no frames either. Check it plays, or describe the ad you have in mind instead.',
            ]);
        }

        try {
            $result = $this->ai->generate('ugc_reference_read', [
                'duration' => (string) round($duration, 1),
                'transcript_json' => $segments === [] ? 'none — this reference has no speech' : json_encode(
                    array_map(fn ($s) => [
                        'start' => round((float) ($s['start'] ?? 0), 2),
                        'end' => round((float) ($s['end'] ?? 0), 2),
                        'text' => (string) ($s['text'] ?? ''),
                    ], $segments),
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ),
                'frame_times' => $frames === []
                    ? 'none'
                    : implode(', ', array_map(fn ($f) => $f['at'].'s', $frames)),
            ], 2500, 0.2, [
                'operation' => 'ugc_reference_read',
                // The frames themselves, in the order their times are listed.
                'images' => array_map(fn ($f) => ['url' => $f['url'], 'title' => 'Frame at '.$f['at'].'s'], $frames),
                'image_detail' => 'low',
            ]);

            $parsed = UgcPlan::decodeModelJson((string) ($result['content'] ?? $result['text'] ?? ''));
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

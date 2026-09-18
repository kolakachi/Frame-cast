<?php

namespace App\Services\Ugc;

use App\Models\Asset;
use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Media\MediaTranscriptionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Reads a user's source video into passages for the My Footage flow. Unlike
 * UgcReference (which reads a stranger's ad into a brand-free shape), this
 * read keeps everything — the point is to show the user what we saw so they
 * can correct it before their version is planned.
 *
 * Every passage carries how we know it: 'observed' (heard or clearly seen),
 * 'inferred' (a judgement from framing or timing), or 'unclear' (we could not
 * make it out and need the user). The distinction is the analysis screen.
 */
class UgcFootageReader
{
    public const KINDS = ['observed', 'inferred', 'unclear'];

    public function __construct(
        private readonly AIGenerationAdapter $ai,
        private readonly MediaTranscriptionService $transcription,
        private readonly ?UgcFrameSampler $frames = null,
    ) {}

    /**
     * @param  array{start?: float, end?: float}  $selection
     * @return array{duration: float, speakers: array, passages: array}
     */
    public function read(Asset $asset, array $selection = []): array
    {
        $duration = (float) ($asset->duration_seconds ?? 0);
        // An upload recorded before durations were probed has none on the
        // row. Passing "0 seconds" to the model made it conclude the video
        // was empty and return no passages — probe the file instead, and
        // keep the answer so it is only ever probed once.
        if ($duration <= 0) {
            $duration = (float) ($this->frames?->duration($asset) ?? 0);
            if ($duration > 0) {
                $asset->forceFill(['duration_seconds' => $duration])->save();
            }
        }
        $frames = $this->frames?->sample($asset, $duration) ?? [];

        $segments = [];
        try {
            $timed = $this->transcription->transcribeAssetWithTimestamps($asset);
            $segments = array_values(array_filter((array) ($timed['segments'] ?? [])));
            if ($segments === [] && ! empty($timed['words'])) {
                $segments = UgcPlan::segmentsFromWords((array) $timed['words']);
            }
        } catch (\Throwable $e) {
            Log::info('Footage read has no usable transcript', ['error' => mb_substr($e->getMessage(), 0, 120)]);
        }

        // Trim to the selected passage of the source, when one was chosen.
        $start = max(0.0, (float) ($selection['start'] ?? 0));
        $end = (float) ($selection['end'] ?? 0) ?: $duration;
        if ($end > $start && ($start > 0 || $end < $duration)) {
            $segments = array_values(array_filter($segments, fn ($s) => (float) ($s['end'] ?? 0) > $start && (float) ($s['start'] ?? 0) < $end));
            $frames = array_values(array_filter($frames, fn ($f) => (float) $f['at'] >= $start - 1 && (float) $f['at'] <= $end + 1));
        }

        if ($segments === [] && $frames === []) {
            throw ValidationException::withMessages([
                'source' => 'Nothing could be read from that file — no speech, and no frames either. Check it plays, then try again.',
            ]);
        }

        try {
            $result = $this->ai->generate('ugc_footage_read', [
                'duration' => ($end > $start ? $end - $start : $duration) > 0
                    ? (string) round($end > $start ? $end - $start : $duration, 1)
                    : 'unknown — read it from the frames and transcript timings',
                'transcript_json' => $segments === [] ? 'none — no speech detected' : json_encode(
                    array_map(fn ($s) => [
                        'start' => round((float) ($s['start'] ?? 0), 2),
                        'end' => round((float) ($s['end'] ?? 0), 2),
                        'text' => (string) ($s['text'] ?? ''),
                    ], $segments),
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ),
                'frame_times' => $frames === [] ? 'none' : implode(', ', array_map(fn ($f) => $f['at'].'s', $frames)),
            ], 5000, 0.2, [
                'operation' => 'ugc_footage_read',
                'images' => array_map(fn ($f) => ['url' => $f['url'], 'title' => 'Frame at '.$f['at'].'s'], $frames),
                'image_detail' => 'low',
            ]);

            $parsed = UgcPlan::decodeModelJson((string) ($result['content'] ?? $result['text'] ?? ''));
        } catch (\Throwable $e) {
            Log::warning('Footage read produced nothing usable', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            throw ValidationException::withMessages([
                'source' => 'Could not read that video just now. No credits were spent — try again.',
            ]);
        }

        return [
            'duration' => round((float) ($parsed['duration'] ?? $duration), 1),
            'speakers' => $this->speakers((array) ($parsed['speakers'] ?? [])),
            'passages' => $this->passages((array) ($parsed['passages'] ?? [])),
        ];
    }

    private function speakers(array $raw): array
    {
        $out = [];
        foreach (array_slice($raw, 0, 6) as $i => $sp) {
            if (! is_array($sp)) {
                continue;
            }
            $out[] = [
                'id' => 's'.($i + 1),
                'label' => mb_substr(trim((string) ($sp['label'] ?? 'Speaker '.($i + 1))), 0, 60) ?: 'Speaker '.($i + 1),
                'on_camera' => (bool) ($sp['on_camera'] ?? false),
            ];
        }

        return $out;
    }

    private function passages(array $raw): array
    {
        $out = [];
        foreach (array_slice($raw, 0, 12) as $i => $p) {
            if (! is_array($p)) {
                continue;
            }
            $kind = in_array($p['kind'] ?? '', self::KINDS, true) ? $p['kind'] : 'inferred';
            $out[] = [
                'id' => 'p'.($i + 1),
                'start' => round(max(0, (float) ($p['start'] ?? 0)), 2),
                'end' => round(max(0, (float) ($p['end'] ?? 0)), 2),
                'title' => mb_substr(trim((string) ($p['title'] ?? 'Passage '.($i + 1))), 0, 80) ?: 'Passage '.($i + 1),
                'kind' => $kind,
                'summary' => mb_substr(trim((string) ($p['summary'] ?? '')), 0, 200),
                'transcript' => mb_substr(trim((string) ($p['transcript'] ?? '')), 0, 1000),
                'note' => mb_substr(trim((string) ($p['note'] ?? '')), 0, 500),
            ];
        }

        if ($out === []) {
            throw ValidationException::withMessages([
                'source' => 'The read came back empty. Try again, or trim the selection to the part that matters.',
            ]);
        }

        return $out;
    }
}

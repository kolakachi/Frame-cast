<?php

namespace App\Services\Ugc;

use App\Services\CreditService;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Support\Facades\Log;

/**
 * Decides the shot structure of a UGC ad: which lines are delivered to camera
 * and which cut away to the product.
 *
 * The format is a person talking while the picture cuts to the thing being
 * talked about. Asking a customer to mark every line by hand is the kind of
 * work they bought the product to avoid, so the model plans it and the UI
 * shows the plan as something to adjust rather than something to author.
 *
 * Cost shapes the plan, not just the price tag. Talking segments are billed
 * per segment by CreditService::spokespersonCost — a flat 130 credits for
 * anything up to 8 seconds — so a plan that returns to camera every sentence
 * costs several times one that groups its on-camera lines, for output that is
 * usually worse. That constraint lives in the prompt AND is re-checked here,
 * because a model told to cap something will occasionally exceed it anyway.
 */
class UgcShotPlanner
{
    /** Past this, every extra talking segment is paid-for churn. */
    private const MAX_ON_CAMERA = 4;

    public function __construct(private readonly AIGenerationAdapter $ai) {}

    /**
     * @param  list<string>  $availableFootage  Labels of clips the customer already uploaded.
     * @return array{segments: list<array<string,mixed>>, estimated_credits: int, reasoning: string}
     */
    public function plan(
        string $scriptText,
        string $product = '',
        string $context = '',
        int $durationSeconds = 30,
        string $language = 'en',
        array $availableFootage = [],
    ): array {
        $scriptText = trim($scriptText);
        if ($scriptText === '') {
            return $this->fallback('');
        }

        try {
            $result = $this->ai->generate('ugc_shot_plan', [
                'script_text'       => $scriptText,
                'product'           => $product !== '' ? $product : 'not specified',
                'context'           => $context !== '' ? $context : 'not specified',
                'duration'          => (string) $durationSeconds,
                'language'          => $language,
                'available_footage' => $availableFootage ? implode(', ', $availableFootage) : 'none yet',
            ], 1400, 0.3);

            $parsed = $this->decode($result);
            if ($parsed === null) {
                return $this->fallback($scriptText);
            }

            $segments = $this->normalise($parsed['segments'] ?? []);
            if ($segments === []) {
                return $this->fallback($scriptText);
            }

            return [
                'segments'          => $segments,
                'estimated_credits' => $this->estimateCredits($segments),
                'reasoning'         => (string) ($parsed['reasoning'] ?? ''),
            ];
        } catch (\Throwable $e) {
            Log::warning('UgcShotPlanner: planning failed, falling back to a single take', [
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return $this->fallback($scriptText);
        }
    }

    /**
     * The adapters return their payload under different keys depending on tier,
     * and the premium path can wrap JSON in a markdown fence.
     */
    private function decode(array $result): ?array
    {
        $content = (string) ($result['content'] ?? $result['text'] ?? '');
        if ($content === '') {
            return null;
        }

        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = trim(preg_replace('/^```[a-z]*\n|\n```$/i', '', $content) ?? $content);
        }

        $parsed = json_decode($content, true);

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * Trust nothing from the model: drop empty lines, force the opener to
     * camera, and collapse any run of on-camera segments so a chatty plan
     * cannot quietly multiply the bill.
     *
     * @return list<array<string,mixed>>
     */
    private function normalise(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $seg) {
            if (! is_array($seg)) {
                continue;
            }

            $text = trim((string) ($seg['script_text'] ?? ''));
            if ($text === '') {
                continue; // a segment with nothing to say silently shortens the ad
            }

            $kind = ($seg['kind'] ?? '') === 'b_roll' ? 'b_roll' : 'on_camera';
            $seconds = (float) ($seg['seconds'] ?? 0);
            if ($seconds <= 0) {
                // ~2.6 words/second is a normal conversational read.
                $seconds = max(1.5, round(str_word_count($text) / 2.6, 1));
            }

            $source = (string) ($seg['source'] ?? '');
            if (! in_array($source, ['upload', 'stock', 'generate'], true)) {
                $source = 'stock';
            }

            $clean[] = [
                'kind'          => $kind,
                'script_text'   => $text,
                'seconds'       => $seconds,
                'visual_brief'  => $kind === 'b_roll' ? trim((string) ($seg['visual_brief'] ?? '')) : '',
                'source'        => $kind === 'b_roll' ? $source : null,
            ];
        }

        if ($clean === []) {
            return [];
        }

        // A cut-away opener wastes the only moment that decides whether the ad
        // is watched at all.
        $clean[0]['kind'] = 'on_camera';
        $clean[0]['visual_brief'] = '';
        $clean[0]['source'] = null;

        $clean = $this->mergeAdjacentOnCamera($clean);

        return $this->capOnCameraSegments($clean);
    }

    /**
     * Two talking segments back to back are one shot that got split, and the
     * split is billed twice.
     *
     * @param  list<array<string,mixed>>  $segments
     * @return list<array<string,mixed>>
     */
    private function mergeAdjacentOnCamera(array $segments): array
    {
        $out = [];
        foreach ($segments as $seg) {
            $prev = $out ? $out[count($out) - 1] : null;

            if ($prev && $prev['kind'] === 'on_camera' && $seg['kind'] === 'on_camera') {
                $out[count($out) - 1]['script_text'] = $prev['script_text'].' '.$seg['script_text'];
                $out[count($out) - 1]['seconds'] = round($prev['seconds'] + $seg['seconds'], 1);
                continue;
            }

            $out[] = $seg;
        }

        return $out;
    }

    /**
     * If the plan still exceeds the cap, convert the shortest offenders to
     * b-roll rather than truncating — every word of the script has to survive,
     * and the narration keeps playing over a cut-away either way.
     *
     * @param  list<array<string,mixed>>  $segments
     * @return list<array<string,mixed>>
     */
    private function capOnCameraSegments(array $segments): array
    {
        $onCamera = array_keys(array_filter(
            $segments,
            static fn (array $s): bool => $s['kind'] === 'on_camera',
        ));

        if (count($onCamera) <= self::MAX_ON_CAMERA) {
            return $segments;
        }

        // Never demote the opener.
        $candidates = array_slice($onCamera, 1);
        usort($candidates, static fn ($a, $b) => $segments[$a]['seconds'] <=> $segments[$b]['seconds']);

        $demote = array_slice($candidates, 0, count($onCamera) - self::MAX_ON_CAMERA);
        foreach ($demote as $i) {
            $segments[$i]['kind'] = 'b_roll';
            $segments[$i]['source'] = $segments[$i]['source'] ?? 'stock';
            if ($segments[$i]['visual_brief'] === '') {
                $segments[$i]['visual_brief'] = 'Product in use, matching the narration';
            }
        }

        return $this->mergeAdjacentOnCamera(array_values($segments));
    }

    /**
     * Talking segments carry the cost. B-roll is the customer's own footage or
     * stock, which is why cutting away is cheaper than staying on the face.
     *
     * @param  list<array<string,mixed>>  $segments
     */
    private function estimateCredits(array $segments): int
    {
        $total = 0;
        foreach ($segments as $seg) {
            if ($seg['kind'] === 'on_camera') {
                $total += CreditService::spokespersonCost((float) $seg['seconds']);
            }
        }

        return $total;
    }

    /**
     * No plan: one continuous take. Always generates something usable, and is
     * exactly what the product did before this service existed.
     *
     * @return array{segments: list<array<string,mixed>>, estimated_credits: int, reasoning: string}
     */
    private function fallback(string $scriptText): array
    {
        if (trim($scriptText) === '') {
            return ['segments' => [], 'estimated_credits' => 0, 'reasoning' => ''];
        }

        $seconds = max(1.5, round(str_word_count($scriptText) / 2.6, 1));
        $segments = [[
            'kind'         => 'on_camera',
            'script_text'  => $scriptText,
            'seconds'      => $seconds,
            'visual_brief' => '',
            'source'       => null,
        ]];

        return [
            'segments'          => $segments,
            'estimated_credits' => $this->estimateCredits($segments),
            'reasoning'         => 'Planned as a single take.',
        ];
    }
}

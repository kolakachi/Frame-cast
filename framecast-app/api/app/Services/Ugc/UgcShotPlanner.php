<?php

namespace App\Services\Ugc;

use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Creative decisions are reviewed before spending; invalid plans never silently change format. */
class UgcShotPlanner
{
    public function __construct(private readonly AIGenerationAdapter $ai) {}

    public function plan(string $scriptText, string $product = '', string $context = '', int $durationSeconds = 30,
        string $language = 'en', array $availableFootage = [], string $format = 'auto'): array
    {
        try {
            $result = $this->ai->generate('ugc_shot_plan', [
                'script_text' => trim($scriptText), 'product' => $product, 'context' => $context,
                'duration' => (string) $durationSeconds, 'language' => $language,
                'available_footage' => implode(', ', $availableFootage), 'format' => $format,
            ], 3500, 0.3);
            $content = trim((string) ($result['content'] ?? $result['text'] ?? ''));
            $content = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $content);
            $parsed = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
            $chosen = (string) ($parsed['format'] ?? '');
            if (! in_array($chosen, UgcPlan::FORMATS, true) || ($format !== 'auto' && $format !== $chosen)) {
                throw new \UnexpectedValueException('Director returned a different format.');
            }
            $segments = UgcPlan::normalise($parsed['segments'] ?? [], $chosen);
            $spoken = UgcPlan::script($segments);
            if (trim($scriptText) !== '' && ! UgcPlan::sameScript($scriptText, $spoken)) {
                throw new \UnexpectedValueException('Director changed the supplied spoken script.');
            }

            return [
                'format' => $chosen, 'script' => $spoken, 'segments' => $segments,
                'credits_per_character' => UgcPlan::quote($segments),
                'reasoning' => mb_substr((string) ($parsed['reasoning'] ?? ''), 0, 600),
                'warnings' => UgcPlan::warnings($segments),
            ];
        } catch (\Throwable $e) {
            Log::warning('UGC director returned no usable plan', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            throw ValidationException::withMessages(['plan' => 'The director could not produce a valid plan. No credits were spent. Try again or simplify the brief. Reaction clips need a visual brief, not a spoken script.']);
        }
    }
}

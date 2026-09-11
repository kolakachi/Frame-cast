<?php

namespace App\Services\Generation;

use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Support\RecordsOpenAiUsage;

/**
 * Per-project character board (projects.character_board_json): the canonical
 * appearance sheet for the recurring subject — outfit, hair, accessories.
 * Written by the one-shot planner (text) or from an anchor image (vision,
 * via lock_subject); read by GenerateAIImageJob::characterBoardSuffix on
 * every image prompt so costume stops drifting between scenes.
 * Assistant/admin-facing only — there is deliberately no user UI.
 */
class CharacterBoardService
{
    use RecordsOpenAiUsage;

    /** Persist/replace the project's board. */
    public function set(Project $project, string $sheet, string $source): void
    {
        $project->forceFill([
            'character_board_json' => [
                'sheet'      => mb_substr(trim($sheet), 0, 500),
                'source'     => $source,
                'updated_at' => now()->toIso8601String(),
            ],
        ])->save();
    }

    /**
     * Vision-describe a person's appearance from an image (the lock_subject
     * anchor) into a sheet. The URL must be fetchable by OpenAI directly —
     * pass a presigned B2 URL, never the app's /media proxy (single-threaded
     * artisan serve deadlocks on the callback fetch). Null on any failure.
     */
    public function describeFromImage(string $imageUrl): ?string
    {
        $apiKey = config('services.openai.api_key');
        if (empty($apiKey)) {
            return null;
        }

        $started = microtime(true);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'       => $model = (string) config('services.openai.cheap_model', 'gpt-4o-mini'),
                    ...\App\Support\OpenAiChatParams::tuning($model, 300, 0.2),
                    'messages'    => [
                        ['role' => 'system', 'content' => 'You write character sheets for visual continuity. Describe the main person in the image in 1-2 sentences covering: gender, approximate age, hair (color, length, style), EXACT outfit (each garment + color), and notable accessories. Only the description — no preamble. If there is no person, reply exactly: NONE'],
                        ['role' => 'user', 'content' => [
                            ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'low']],
                        ]],
                    ],
                ]);

            if (! $response->successful()) {
                $this->recordOpenAiUsage('character_board', $model, 'failed', [], $started, 'http_'.$response->status());
                Log::warning('CharacterBoardService: vision call failed', [
                    'status' => $response->status(),
                    'body'   => mb_substr($response->body(), 0, 300),
                ]);
                return null;
            }

            $json = $response->json();
            $this->recordOpenAiUsage('character_board', $model, 'succeeded', (array) ($json['usage'] ?? []), $started);

            $text = trim((string) data_get($json, 'choices.0.message.content', ''));
            if ($text === '' || strtoupper($text) === 'NONE') {
                return null;
            }

            return mb_substr($text, 0, 500);
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }
}

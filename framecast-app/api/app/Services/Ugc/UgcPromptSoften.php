<?php

namespace App\Services\Ugc;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A clip prompt the video model's moderation declined, rewritten in neutral words and tried once more (RD-01, from
 * Pixelle-Video, 2026-10-15). Tasteful ads trip cautious filters on wording (skin, body, bed, a child's product, a
 * medical claim); the same shot in plainer words usually passes. Every action, the product, the people and the framing
 * stay; nothing is added. Null when the rewrite could not be made: the clip then fails as it did.
 */
class UgcPromptSoften
{
    public function rewrite(string $prompt): ?string
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') return null;
        $ask = "A video model's content filter declined this prompt for a tasteful product ad. Rewrite it so a cautious filter "
            ."accepts it: keep every action, the product, the people, the place, the framing and any quoted spoken line word for "
            ."word; replace only wording that could read as sensitive (body parts, skin, undress, bed, intimacy, injury, minors, "
            ."medical or weight-loss claims) with neutral, everyday wording. Do not add anything. Return only the rewritten prompt.\n\n"
            .$prompt;
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(90)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('services.ai.direct_model', 'claude-sonnet-5-5'),
                    'max_tokens' => 4000, 'messages' => [['role' => 'user', 'content' => $ask]]]);
            if (! $r->successful()) return null;
            $text = trim(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''));
        } catch (\Throwable $e) {
            Log::warning('ugc soften: could not run', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }
        // A rewrite that lost most of the prompt is not the same shot.
        return $text !== '' && mb_strlen($text) >= 0.6 * mb_strlen($prompt) ? $text : null;
    }
}

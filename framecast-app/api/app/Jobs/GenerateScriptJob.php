<?php

namespace App\Jobs;

use App\Jobs\BreakdownScenesJob;
use App\Events\GenerationProgressed;
use App\Models\Project;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GenerateScriptJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(AIGenerationAdapter $aiGeneration): void
    {
        GenerationProgressed::dispatch($this->projectId, 'script', 'processing');

        $project = Project::query()->find($this->projectId);

        if (! $project) {
            return;
        }

        $promptTemplateKey = $this->promptTemplateKey((string) $project->source_type);
        $sourceContent = $this->sourceContentForGeneration($project);

        $result = $aiGeneration->generate($promptTemplateKey, [
            'source_type' => $project->source_type ?: 'prompt',
            'tone' => $project->tone ?: 'neutral',
            'content_goal' => $project->content_goal ?: 'educational',
            'language' => $project->primary_language ?: 'en',
            'source_content' => $sourceContent,
        ]);

        $project->forceFill([
            'script_text' => $result['content'],
        ])->save();

        GenerationProgressed::dispatch($this->projectId, 'script', 'completed');
        BreakdownScenesJob::dispatch($project->getKey());
    }

    public function failed(\Throwable $exception): void
    {
        Project::query()
            ->whereKey($this->projectId)
            ->update([
                'status' => 'failed',
            ]);

        GenerationProgressed::dispatch($this->projectId, 'script', 'failed', $exception->getMessage());
    }

    private function promptTemplateKey(string $sourceType): string
    {
        return match ($sourceType) {
            'url' => 'script_from_url',
            'product_description' => 'script_from_product',
            'csv_topic' => 'script_from_csv',
            'audio_upload' => 'script_from_audio_reference',
            'video_upload' => 'script_from_video_reference',
            default => 'script_from_prompt',
        };
    }

    private function sourceContentForGeneration(Project $project): string
    {
        $source = trim((string) $project->source_content_raw);

        if ($project->source_type !== 'url') {
            return $source;
        }

        return $this->fetchUrlContent($source) ?: "URL: {$source}";
    }

    private function fetchUrlContent(string $url): ?string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'FramecastBot/1.0'])
                ->get($url);

            if (! $response->ok()) {
                return null;
            }

            $text = html_entity_decode(strip_tags((string) $response->body()));
            $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

            return $text !== '' ? Str::limit($text, 6000, '') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Jobs\MatchVisualsJob;
use App\Models\Project;
use App\Models\ProjectHookOption;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class GenerateHooksJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(AIGenerationAdapter $aiGeneration): void
    {
        GenerationProgressed::dispatch($this->projectId, 'hooks', 'processing');

        $project = Project::query()->find($this->projectId);

        if (! $project || ! $project->script_text) {
            return;
        }

        $result = $aiGeneration->generate('hook_options', [
            'script_text' => $project->script_text,
            'language' => $project->primary_language ?: 'en',
        ], maxTokens: 500, temperature: 0.7);

        $hooks = $this->extractHooks($result['content'], $project->script_text);

        DB::transaction(function () use ($project, $hooks): void {
            ProjectHookOption::query()->where('project_id', $project->getKey())->delete();

            foreach ($hooks as $index => $hookText) {
                ProjectHookOption::query()->create([
                    'project_id' => $project->getKey(),
                    'sort_order' => $index + 1,
                    'hook_text' => $hookText,
                ]);
            }

        });

        GenerationProgressed::dispatch($this->projectId, 'hooks', 'completed');
        MatchVisualsJob::dispatch($project->getKey());
    }

    public function failed(\Throwable $exception): void
    {
        Project::query()
            ->whereKey($this->projectId)
            ->update([
                'status' => 'failed',
            ]);

        GenerationProgressed::dispatch($this->projectId, 'hooks', 'failed', $exception->getMessage());
    }

    /**
     * @return list<string>
     */
    private function extractHooks(string $content, string $scriptText): array
    {
        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            $rows = $decoded['hooks'] ?? $decoded;

            if (is_array($rows)) {
                $hooks = [];

                foreach ($rows as $row) {
                    $text = is_array($row) ? (string) ($row['text'] ?? $row['hook'] ?? '') : (string) $row;
                    $text = trim($text);

                    if ($text !== '') {
                        $hooks[] = $text;
                    }
                }

                if ($hooks !== []) {
                    return $this->normalizeCount($hooks);
                }
            }
        }

        $lineHooks = array_values(array_filter(array_map(
            static fn (string $line): string => trim(preg_replace('/^\d+[\).\-\s]*/', '', $line) ?? ''),
            preg_split('/\r?\n/', trim($content)) ?: [],
        )));

        if ($lineHooks !== []) {
            return $this->normalizeCount($lineHooks);
        }

        return $this->normalizeCount([
            'Stop scrolling: this changes everything.',
            'You are doing this the hard way.',
            'This simple shift boosts results fast.',
            'Most people miss this key step.',
            'Try this framework in your next video.',
        ]);
    }

    /**
     * @param  list<string>  $hooks
     * @return list<string>
     */
    private function normalizeCount(array $hooks): array
    {
        $trimmed = array_values(array_unique(array_map(
            static fn (string $hook): string => mb_substr(trim($hook), 0, 180),
            $hooks,
        )));

        if (count($trimmed) < 3) {
            $trimmed[] = 'Here is the shortcut no one mentions.';
            $trimmed[] = 'Use this to save hours every week.';
            $trimmed[] = 'Start with this one move today.';
            $trimmed = array_values(array_unique($trimmed));
        }

        return array_slice($trimmed, 0, 10);
    }
}

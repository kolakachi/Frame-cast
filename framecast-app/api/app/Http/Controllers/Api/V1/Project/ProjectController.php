<?php

namespace App\Http\Controllers\Api\V1\Project;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->workspace_id) {
            return $this->error('workspace_required', 'User is not assigned to a workspace.', 422);
        }

        $validated = $request->validate([
            'source_type' => ['required', Rule::in($this->allowedSourceTypes())],
            'source_content_raw' => ['nullable', 'string'],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => ['required', 'string', 'max:16'],
            'platform_target' => ['required', 'string', 'max:64'],
            'aspect_ratio' => ['required', Rule::in(['9:16', '1:1', '16:9'])],
            'channel_id' => ['nullable', 'integer'],
            'template_id' => ['nullable', 'integer'],
            'brand_kit_id' => ['nullable', 'integer'],
            'content_goal' => ['nullable', 'string', 'max:255'],
            'duration_target_seconds' => ['nullable', 'integer', 'min:5', 'max:600'],
            'tone' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $sourceError = $this->validateSourceContent($validated['source_type'], $validated['source_content_raw'] ?? null);

        if ($sourceError) {
            return $this->error('invalid_source_content', $sourceError, 422);
        }

        $channel = null;

        if (! empty($validated['channel_id'])) {
            $channel = Channel::query()
                ->whereKey($validated['channel_id'])
                ->where('workspace_id', $user->workspace_id)
                ->first();

            if (! $channel) {
                return $this->error('invalid_channel', 'Selected channel does not exist in this workspace.', 422);
            }
        }

        $template = null;

        if (! empty($validated['template_id'])) {
            $template = Template::query()
                ->whereKey($validated['template_id'])
                ->where(function ($query) use ($user): void {
                    $query->whereNull('workspace_id')
                        ->orWhere('workspace_id', $user->workspace_id);
                })
                ->first();

            if (! $template) {
                return $this->error('invalid_template', 'Selected template is not available in this workspace.', 422);
            }
        }

        $allowedTemplateIds = $channel?->allowed_template_ids;

        if ($channel && is_array($allowedTemplateIds) && $allowedTemplateIds !== []) {
            $allowedTemplateIds = array_map(static fn (mixed $id): int => (int) $id, $allowedTemplateIds);

            if ($template && ! in_array((int) $template->getKey(), $allowedTemplateIds, true)) {
                return $this->error('template_channel_conflict', 'Selected template is not allowed for this channel.', 422);
            }

            if (! $template) {
                $template = Template::query()
                    ->whereIn('id', $allowedTemplateIds)
                    ->where(function ($query) use ($user): void {
                        $query->whereNull('workspace_id')
                            ->orWhere('workspace_id', $user->workspace_id);
                    })
                    ->first();
            }
        }

        $brandKitId = $validated['brand_kit_id'] ?? $channel?->brand_kit_id;

        $project = Project::query()->create([
            'workspace_id' => $user->workspace_id,
            'channel_id' => $channel?->getKey(),
            'brand_kit_id' => $brandKitId,
            'template_id' => $template?->getKey(),
            'source_type' => $validated['source_type'],
            'source_content_raw' => $validated['source_content_raw'] ?? null,
            'source_content_normalized' => $this->normalizeSource($validated['source_content_raw'] ?? ''),
            'content_goal' => $validated['content_goal'] ?? null,
            'platform_target' => $validated['platform_target'],
            'duration_target_seconds' => $validated['duration_target_seconds'] ?? null,
            'aspect_ratio' => $validated['aspect_ratio'],
            'tone' => $validated['tone'] ?? null,
            'primary_language' => $validated['languages'][0],
            'title' => $validated['title'] ?? null,
            'status' => 'draft',
            'created_by_user_id' => $user->getKey(),
        ]);

        return response()->json([
            'data' => [
                'project' => $this->serializeProject($project),
                'defaults' => [
                    'channel_id' => $channel?->getKey(),
                    'brand_kit_id' => $brandKitId,
                    'default_voice_profile_id' => $channel?->default_voice_profile_id,
                    'default_caption_preset_id' => $channel?->default_caption_preset_id,
                    'template_id' => $template?->getKey(),
                ],
            ],
            'meta' => [],
        ], 201);
    }

    /**
     * @return list<string>
     */
    private function allowedSourceTypes(): array
    {
        return [
            'prompt',
            'script',
            'url',
            'product_description',
            'csv_topic',
            'audio_upload',
            'video_upload',
        ];
    }

    private function validateSourceContent(string $sourceType, ?string $source): ?string
    {
        $trimmed = trim((string) $source);

        if ($trimmed === '') {
            return 'Source content is required for the selected source type.';
        }

        if ($sourceType === 'url' && ! filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return 'URL source type requires a valid URL.';
        }

        if (in_array($sourceType, ['prompt', 'script', 'product_description'], true) && mb_strlen($trimmed) < 10) {
            return 'Text source content must be at least 10 characters.';
        }

        return null;
    }

    private function normalizeSource(string $source): string
    {
        return trim(preg_replace('/\s+/', ' ', $source) ?? '');
    }

    /**
     * @return array{
     *     id:int,
     *     workspace_id:int,
     *     channel_id:?int,
     *     brand_kit_id:?int,
     *     template_id:?int,
     *     source_type:string,
     *     source_content_raw:?string,
     *     source_content_normalized:?string,
     *     content_goal:?string,
     *     platform_target:?string,
     *     duration_target_seconds:?int,
     *     aspect_ratio:?string,
     *     tone:?string,
     *     primary_language:?string,
     *     title:?string,
     *     script_text:?string,
     *     status:string,
     *     created_by_user_id:?int,
     *     created_at:?string,
     *     updated_at:?string
     * }
     */
    private function serializeProject(Project $project): array
    {
        return [
            'id' => $project->getKey(),
            'workspace_id' => $project->workspace_id,
            'channel_id' => $project->channel_id,
            'brand_kit_id' => $project->brand_kit_id,
            'template_id' => $project->template_id,
            'source_type' => $project->source_type,
            'source_content_raw' => $project->source_content_raw,
            'source_content_normalized' => $project->source_content_normalized,
            'content_goal' => $project->content_goal,
            'platform_target' => $project->platform_target,
            'duration_target_seconds' => $project->duration_target_seconds,
            'aspect_ratio' => $project->aspect_ratio,
            'tone' => $project->tone,
            'primary_language' => $project->primary_language,
            'title' => $project->title,
            'script_text' => $project->script_text,
            'status' => $project->status,
            'created_by_user_id' => $project->created_by_user_id,
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}

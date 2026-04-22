<?php

namespace App\Http\Controllers\Api\V1\Channel;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\User;
use App\Services\WorkspaceUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    public function __construct(private readonly WorkspaceUsageService $usageService) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $channels = Channel::query()
            ->where('workspace_id', $user->workspace_id)
            ->withCount('projects')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => [
                'channels' => $channels->map(fn (Channel $channel): array => $this->serializeChannel($channel))->all(),
            ],
            'meta' => [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->workspace_id) {
            return $this->error('workspace_required', 'User is not assigned to a workspace.', 422);
        }

        if ($this->usageService->hasReachedChannelLimit($user)) {
            return $this->error(
                'channel_limit_reached',
                'You have reached the active channel limit for the current plan. Upgrade to add more channels.',
                422,
            );
        }

        $validated = $request->validate($this->rules());

        $channel = Channel::query()->create([
            ...$validated,
            'workspace_id' => $user->workspace_id,
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json([
            'data' => [
                'channel' => $this->serializeChannel($channel),
            ],
            'meta' => [],
        ], 201);
    }

    public function show(Request $request, int $channelId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $channel = $this->findForUser($user, $channelId);

        if (! $channel) {
            return $this->error('not_found', 'Channel not found.', 404);
        }

        return response()->json([
            'data' => [
                'channel' => $this->serializeChannel($channel),
            ],
            'meta' => [],
        ]);
    }

    public function update(Request $request, int $channelId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $channel = $this->findForUser($user, $channelId);

        if (! $channel) {
            return $this->error('not_found', 'Channel not found.', 404);
        }

        $validated = $request->validate($this->rules(true));
        $channel->fill($validated)->save();

        return response()->json([
            'data' => [
                'channel' => $this->serializeChannel($channel->fresh()),
            ],
            'meta' => [],
        ]);
    }

    public function destroy(Request $request, int $channelId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $channel = $this->findForUser($user, $channelId);

        if (! $channel) {
            return $this->error('not_found', 'Channel not found.', 404);
        }

        $channel->forceFill([
            'status' => 'archived',
        ])->save();

        return response()->json([
            'data' => [
                'archived' => true,
                'channel' => $this->serializeChannel($channel->fresh()),
            ],
            'meta' => [],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $nullable = $partial ? 'sometimes' : 'nullable';

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => [$nullable, 'nullable', 'string'],
            'default_language' => [$nullable, 'nullable', 'string', 'max:16'],
            'platform_targets' => [$nullable, 'nullable', 'array'],
            'platform_targets.*' => ['string', 'max:64'],
            'default_voice_profile_id' => [$nullable, 'nullable', 'integer'],
            'default_caption_preset_id' => [$nullable, 'nullable', 'integer'],
            'allowed_template_ids' => [$nullable, 'nullable', 'array'],
            'allowed_template_ids.*' => ['integer'],
            'brand_kit_id' => [$nullable, 'nullable', 'integer'],
            'status' => [$nullable, 'nullable', 'in:active,archived'],
        ];
    }

    private function findForUser(User $user, int $channelId): ?Channel
    {
        return Channel::query()
            ->whereKey($channelId)
            ->where('workspace_id', $user->workspace_id)
            ->first();
    }

    /**
     * @return array{
     *     id:int,
     *     workspace_id:int,
     *     name:string,
     *     description:?string,
     *     default_language:?string,
     *     platform_targets:?array,
     *     default_voice_profile_id:?int,
     *     default_caption_preset_id:?int,
     *     allowed_template_ids:?array,
     *     brand_kit_id:?int,
     *     status:string,
     *     created_at:?string,
     *     updated_at:?string
     * }
     */
    private function serializeChannel(Channel $channel): array
    {
        return [
            'id' => $channel->getKey(),
            'workspace_id' => $channel->workspace_id,
            'name' => $channel->name,
            'description' => $channel->description,
            'default_language' => $channel->default_language,
            'platform_targets' => $channel->platform_targets,
            'default_voice_profile_id' => $channel->default_voice_profile_id,
            'default_caption_preset_id' => $channel->default_caption_preset_id,
            'allowed_template_ids' => $channel->allowed_template_ids,
            'brand_kit_id' => $channel->brand_kit_id,
            'status' => $channel->status,
            'projects_count' => (int) ($channel->projects_count ?? 0),
            'created_at' => $channel->created_at?->toIso8601String(),
            'updated_at' => $channel->updated_at?->toIso8601String(),
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

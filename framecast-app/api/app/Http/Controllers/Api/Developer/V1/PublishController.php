<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Http\Controllers\Api\V1\Publishing\ScheduledPostController as AppScheduledPostController;
use App\Models\Project;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Services\CreditService;
use App\Services\Developer\EditOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Publishing through the API. Posting is outward-facing and cannot be undone,
 * so it needs the current revision, an explicit completed export, the
 * connected account by id and confirm=true; the app's own scheduled-post
 * controller then applies its plan gate, account check and publish job.
 * Nothing here is quoted: publishing spends no credits.
 */
class PublishController extends DeveloperController
{
    private const ACTIVE = ['scheduled', 'publishing', 'published'];

    /** Connected social accounts this workspace can post to. */
    public function accounts(Request $request): JsonResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $accounts = SocialAccount::query()->where('workspace_id', $workspaceId)->orderBy('platform')->orderBy('created_at')->get()
            ->map(fn (SocialAccount $a) => [
                'id' => $a->getKey(), 'platform' => $a->platform, 'username' => $a->platform_username,
                'display_name' => $a->platform_display_name, 'status' => $a->status,
                'can_post' => $a->status === 'active',
            ])->values()->all();
        $available = app(CreditService::class)->canPublishToSocial($workspaceId);

        return response()->json(['data' => [
            'accounts' => $accounts, 'publishing_available' => $available,
            'connect_in_app' => rtrim((string) config('app.frontend_url'), '/').'/settings/social',
            'next' => $accounts === [] ? 'No account is connected. Accounts are connected in the app under Settings → Social accounts.' : ($available ? null : 'This plan cannot publish to social platforms.'),
        ], 'meta' => ['count' => count($accounts)]]);
    }

    /** Post a completed export to a connected account now, or at a time. */
    public function publish(Request $request, int $videoId): JsonResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $project = Project::query()->whereKey($videoId)->where('workspace_id', $workspaceId)->first();
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }
        $input = $this->validated($request, [
            'revision' => ['required', 'string', 'max:64'],
            'export_id' => ['required', 'integer', 'min:1'],
            'social_account_id' => ['required', 'integer', 'min:1'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'title' => ['nullable', 'string', 'max:512'],
            'description' => ['nullable', 'string', 'max:5000'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted', 'private'])],
            'hashtags' => ['nullable', 'array', 'max:30'],
            'hashtags.*' => ['string', 'max:100'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'confirm' => ['required', 'boolean'],
            'allow_stale' => ['sometimes', 'boolean'],
        ]);
        if (! $input['confirm']) {
            return $this->fail('confirmation_required', 'Publishing posts to an external account and cannot be undone. Send confirm=true only after the user approved the account, caption and time.', 422);
        }
        if ($input['revision'] !== EditorController::revision($project)) {
            return $this->fail('revision_conflict', 'The project changed. Read it again before publishing.', 409, ['current_revision' => EditorController::revision($project)]);
        }
        if (! app(CreditService::class)->canPublishToSocial($workspaceId)) {
            return $this->fail('upgrade_required', 'Social publishing is not available on this plan.', 402);
        }
        $account = SocialAccount::query()->whereKey($input['social_account_id'])->where('workspace_id', $workspaceId)->first();
        if (! $account) {
            return $this->fail('not_found', 'Social account not found in this workspace.', 404);
        }
        if ($account->status !== 'active') {
            return $this->fail('account_disconnected', 'This social account is not connected. Reconnect it in the app.', 409);
        }

        // The result endpoint enforces ownership, completed output,
        // latest-version selection and explicit stale-version consent.
        $checked = app(VideoController::class)->result(EditOperations::inner($request, [
            'export_id' => $input['export_id'], 'allow_stale' => $input['allow_stale'] ?? false,
        ], 'GET'), $videoId);
        if ($checked->getStatusCode() >= 400) {
            return $checked;
        }

        // A repeated call for the same export and account within a short
        // window returns the post it already made rather than posting twice.
        $wanted = empty($input['scheduled_at']) ? null : \Illuminate\Support\Carbon::parse($input['scheduled_at']);
        $existing = ScheduledPost::query()->where('workspace_id', $workspaceId)->where('export_job_id', $input['export_id'])
            ->where('social_account_id', $account->getKey())->whereIn('status', self::ACTIVE)
            ->where('created_at', '>=', now()->subMinutes(10))->latest('id')->get()
            ->first(fn (ScheduledPost $p) => $wanted
                ? ($p->scheduled_at && $p->scheduled_at->equalTo($wanted))
                : ($p->scheduled_at && $p->scheduled_at->diffInSeconds($p->created_at) <= 60));
        if ($existing) {
            return response()->json(['data' => ['post' => $this->post($existing)] + ['replayed' => true], 'meta' => []], 200);
        }

        $payload = array_filter([
            'export_job_id' => $input['export_id'], 'social_account_id' => $account->getKey(),
            'caption' => $input['caption'] ?? null, 'title' => $input['title'] ?? null, 'description' => $input['description'] ?? null,
            'visibility' => $input['visibility'] ?? null, 'hashtags' => $input['hashtags'] ?? null,
            'scheduled_at' => $input['scheduled_at'] ?? null, 'publish_now' => empty($input['scheduled_at']),
        ], fn ($v) => $v !== null);
        $result = $this->delegate($request, $payload);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $post = ScheduledPost::query()->whereKey((int) ($result['post']['id'] ?? 0))->where('workspace_id', $workspaceId)->first();
        if (! $post) {
            return $this->fail('publish_failed', 'The post could not be recorded.', 500);
        }

        return response()->json(['data' => ['post' => $this->post($post)], 'meta' => []], 201);
    }

    public function posts(Request $request, int $videoId): JsonResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        if (! Project::query()->whereKey($videoId)->where('workspace_id', $workspaceId)->exists()) {
            return $this->fail('not_found', 'Video not found.', 404);
        }
        $posts = ScheduledPost::query()->where('workspace_id', $workspaceId)->where('project_id', $videoId)->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => ['posts' => $posts->map(fn ($p) => $this->post($p))->values()->all()], 'meta' => ['count' => $posts->count()]]);
    }

    public function show(Request $request, int $videoId, int $postId): JsonResponse
    {
        $post = ScheduledPost::query()->whereKey($postId)->where('workspace_id', (int) $request->user()->workspace_id)->where('project_id', $videoId)->first();
        if (! $post) {
            return $this->fail('not_found', 'Post not found.', 404);
        }

        return response()->json(['data' => ['post' => $this->post($post)], 'meta' => []]);
    }

    private function post(ScheduledPost $p): array
    {
        $state = match ($p->status) {
            'published' => 'published',
            'failed' => 'failed',
            'scheduled' => $p->scheduled_at && $p->scheduled_at->isFuture() ? 'scheduled' : 'publishing',
            default => $p->status,
        };
        $account = $p->socialAccount;

        return [
            'id' => $p->getKey(), 'video_id' => $p->project_id, 'export_id' => $p->export_job_id,
            'platform' => $p->platform, 'status' => $state,
            'account' => $account ? ['id' => $account->getKey(), 'platform' => $account->platform, 'username' => $account->platform_username, 'display_name' => $account->platform_display_name] : null,
            'scheduled_at' => $p->scheduled_at?->toIso8601String(), 'published_at' => $p->published_at?->toIso8601String(),
            'post_url' => $p->platform_post_url, 'caption' => $p->caption, 'title' => $p->title, 'visibility' => $p->visibility,
            'hashtags' => $p->hashtags ?? [], 'failure' => $p->failure_reason ? ['message' => $p->failure_reason, 'attempts' => (int) $p->attempt_count] : null,
            'retry_after_seconds' => $state === 'publishing' ? 30 : null,
            'next' => match ($state) {
                'published' => 'Published. Share post_url with the user.',
                'publishing' => 'Posting now; poll get_post until published or failed.',
                'scheduled' => 'Scheduled. The post goes out at scheduled_at; changes and cancellation are in the app scheduler.',
                'failed' => 'The platform refused the post. Fix the cause in the app and retry there.',
                default => null,
            },
        ];
    }

    private function delegate(Request $outer, array $payload): array|JsonResponse
    {
        $inner = Request::create('/internal/scheduled-posts', 'POST', $payload);
        $inner->headers->set('Accept', 'application/json');
        $inner->setUserResolver(fn () => $outer->user());
        $inner->attributes->set('api_key_id', $outer->attributes->get('api_key_id'));
        try {
            $response = app(AppScheduledPostController::class)->store($inner);
        } catch (ValidationException $e) {
            return $this->fail('validation_failed', collect($e->errors())->flatten()->first() ?? 'Invalid request.', 422, ['errors' => $e->errors()]);
        }
        $status = $response->getStatusCode();
        $body = $response->getData(true);
        if ($status >= 400) {
            $err = $body['error'] ?? ['code' => 'refused', 'message' => 'The request was refused.'];
            $code = $err['code'] === 'plan_social_publishing_disabled' ? 'upgrade_required' : $err['code'];

            return $this->fail($code, $err['message'] ?? 'Refused.', $status, $err['context'] ?? []);
        }

        return $body['data'] ?? [];
    }
}

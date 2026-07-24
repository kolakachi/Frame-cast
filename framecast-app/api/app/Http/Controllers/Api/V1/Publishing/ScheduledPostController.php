<?php

namespace App\Http\Controllers\Api\V1\Publishing;

use App\Http\Controllers\Controller;
use App\Jobs\PublishVideoJob;
use App\Models\ExportJob;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScheduledPostController extends Controller
{
    // ── List posts (for calendar) ────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'from'     => ['nullable', 'date'],
            'to'       => ['nullable', 'date'],
            'platform' => ['nullable', 'string'],
            'status'   => ['nullable', 'string'],
        ]);

        // A post's calendar date is scheduled_at when set, otherwise the time
        // it actually published (published-now / legacy posts have no
        // scheduled_at), otherwise when it was created. Filter and sort on that
        // same effective date the calendar UI uses, so published posts with a
        // null scheduled_at aren't silently dropped from the range.
        $effectiveDate = 'COALESCE(scheduled_at, published_at, created_at)';

        $posts = ScheduledPost::query()
            ->where('workspace_id', $user->workspace_id)
            ->with(['project:id,title,series_id', 'project.series:id,name', 'socialAccount:id,platform,platform_display_name,platform_username'])
            ->when($validated['from'] ?? null, fn ($q, $v) => $q->whereRaw("{$effectiveDate} >= ?", [$v]))
            ->when($validated['to'] ?? null, fn ($q, $v) => $q->whereRaw("{$effectiveDate} <= ?", [$v]))
            ->when($validated['platform'] ?? null, fn ($q, $v) => $q->where('platform', $v))
            ->when($validated['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByRaw($effectiveDate)
            ->get();

        return response()->json(['data' => ['posts' => $posts->map(fn (ScheduledPost $p) => $this->serialize($p))->all()]]);
    }

    // ── Create / schedule ────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // ── Free-tier social publish block ──────────────────────────────────
        // Free tier can render + download MP4s but cannot publish to social
        // platforms. Upgrade lever — the moment a free user wants to ship to
        // TikTok / IG / YT, they're nudged to upgrade.
        $credits = app(\App\Services\CreditService::class);
        if (! $credits->canPublishToSocial((int) $user->workspace_id)) {
            $planTier = $credits->planTier((int) $user->workspace_id);
            return response()->json([
                'error' => [
                    'code'    => 'plan_social_publishing_disabled',
                    'message' => "Your {$planTier} plan can download MP4s but not publish directly to social platforms. Upgrade to Starter or above to schedule + post to TikTok, Instagram, YouTube, and Facebook.",
                    'context' => ['plan' => $planTier, 'feature' => 'social_publishing'],
                ],
            ], 402);
        }

        $validated = $request->validate([
            'export_job_id'     => ['required', 'integer'],
            'social_account_id' => ['required', 'integer'],
            'caption'           => ['nullable', 'string', 'max:5000'],
            'title'             => ['nullable', 'string', 'max:512'],
            'description'       => ['nullable', 'string', 'max:5000'],
            'category'          => ['nullable', 'string', 'max:128'],
            'visibility'        => ['nullable', Rule::in(['public', 'unlisted', 'private'])],
            'hashtags'          => ['nullable', 'array'],
            'hashtags.*'        => ['string', 'max:100'],
            'scheduled_at'      => ['nullable', 'date', 'after:now'],
            'publish_now'       => ['nullable', 'boolean'],
        ]);

        $exportJob = ExportJob::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('status', 'completed')
            ->find($validated['export_job_id']);

        if (! $exportJob) {
            return $this->error('not_found', 'Completed export not found.', 404);
        }

        $account = SocialAccount::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('status', 'active')
            ->find($validated['social_account_id']);

        if (! $account) {
            return $this->error('not_found', 'Social account not found or disconnected.', 404);
        }

        $publishNow = (bool) ($validated['publish_now'] ?? false);
        $status     = $publishNow ? 'scheduled' : (isset($validated['scheduled_at']) ? 'scheduled' : 'draft');

        $post = ScheduledPost::query()->create([
            'workspace_id'      => $user->workspace_id,
            'project_id'        => $exportJob->project_id,
            'export_job_id'     => $exportJob->getKey(),
            'social_account_id' => $account->getKey(),
            'platform'          => $account->platform,
            'status'            => $status,
            'scheduled_at'      => $publishNow ? now() : ($validated['scheduled_at'] ?? null),
            'caption'           => $validated['caption'] ?? null,
            'title'             => $validated['title'] ?? null,
            'description'       => $validated['description'] ?? null,
            'category'          => $validated['category'] ?? null,
            'visibility'        => $validated['visibility'] ?? 'public',
            'hashtags'          => $validated['hashtags'] ?? [],
        ]);

        if ($status === 'scheduled') {
            PublishVideoJob::dispatch($post->getKey())
                ->delay($publishNow ? 0 : now()->diffInSeconds($post->scheduled_at));
        }

        return response()->json(['data' => ['post' => $this->serialize($post)]], 201);
    }

    // ── Update (edit caption / reschedule) ───────────────────────────────────

    public function update(Request $request, int $postId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $post = ScheduledPost::query()
            ->where('workspace_id', $user->workspace_id)
            ->whereIn('status', ['draft', 'scheduled', 'failed'])
            ->find($postId);

        if (! $post) {
            return $this->error('not_found', 'Post not found or cannot be edited.', 404);
        }

        $validated = $request->validate([
            'caption'      => ['sometimes', 'nullable', 'string', 'max:5000'],
            'title'        => ['sometimes', 'nullable', 'string', 'max:512'],
            'description'  => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category'     => ['sometimes', 'nullable', 'string', 'max:128'],
            'visibility'   => ['sometimes', 'nullable', Rule::in(['public', 'unlisted', 'private'])],
            'hashtags'     => ['sometimes', 'nullable', 'array'],
            'scheduled_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $post->update($validated);

        // Re-queue if rescheduled
        if (isset($validated['scheduled_at']) && $post->status === 'scheduled') {
            PublishVideoJob::dispatch($post->getKey())
                ->delay(now()->diffInSeconds($post->scheduled_at));
        }

        // Retry a failed post
        if ($post->status === 'failed') {
            $post->update(['status' => 'scheduled']);
            PublishVideoJob::dispatch($post->getKey());
        }

        return response()->json(['data' => ['post' => $this->serialize($post->fresh())]]);
    }

    // ── Cancel ───────────────────────────────────────────────────────────────

    public function destroy(Request $request, int $postId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $post = ScheduledPost::query()
            ->where('workspace_id', $user->workspace_id)
            ->whereIn('status', ['draft', 'scheduled', 'failed'])
            ->find($postId);

        if (! $post) {
            return $this->error('not_found', 'Post not found or already published.', 404);
        }

        $post->update(['status' => 'cancelled']);

        return response()->json(['data' => ['cancelled' => true]]);
    }

    // ── Retry a failed post ───────────────────────────────────────────────────

    public function retry(Request $request, int $postId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $post = ScheduledPost::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('status', 'failed')
            ->find($postId);

        if (! $post) {
            return $this->error('not_found', 'Failed post not found.', 404);
        }

        $post->update(['status' => 'scheduled', 'failure_reason' => null]);
        PublishVideoJob::dispatch($post->getKey());

        return response()->json(['data' => ['post' => $this->serialize($post->fresh())]]);
    }

    // ── Completed exports available for scheduling ───────────────────────────

    public function completedExports(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $exports = ExportJob::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('status', 'completed')
            ->with('project:id,title')
            ->orderByDesc('completed_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => [
                'exports' => $exports->map(fn (ExportJob $e) => [
                    'id'           => $e->getKey(),
                    'project_id'   => $e->project_id,
                    'project_title'=> $e->project?->title ?? 'Untitled',
                    'aspect_ratio' => $e->aspect_ratio,
                    'language'     => $e->language,
                    'file_name'    => $e->file_name,
                    'completed_at' => $e->completed_at?->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    // ── Serializer ───────────────────────────────────────────────────────────

    private function serialize(ScheduledPost $post): array
    {
        return [
            'id'                => $post->getKey(),
            'platform'          => $post->platform,
            'status'            => $post->status,
            'scheduled_at'      => $post->scheduled_at?->toIso8601String(),
            'published_at'      => $post->published_at?->toIso8601String(),
            'platform_post_id'  => $post->platform_post_id,
            'platform_post_url' => $post->platform_post_url,
            'caption'           => $post->caption,
            'title'             => $post->title,
            'description'       => $post->description,
            'category'          => $post->category,
            'visibility'        => $post->visibility,
            'hashtags'          => $post->hashtags ?? [],
            'failure_reason'    => $post->failure_reason,
            'attempt_count'     => $post->attempt_count,
            'project_id'        => $post->project_id,
            'export_job_id'     => $post->export_job_id,
            'social_account_id' => $post->social_account_id,
            'project_title'     => $post->project?->title,
            'series_id'         => $post->project?->series_id,
            'series_name'       => $post->project?->series?->name,
            'account'           => $post->socialAccount ? [
                'id'           => $post->socialAccount->getKey(),
                'platform'     => $post->socialAccount->platform,
                'display_name' => $post->socialAccount->platform_display_name,
                'username'     => $post->socialAccount->platform_username,
            ] : null,
            'created_at'        => $post->created_at?->toIso8601String(),
        ];
    }
}

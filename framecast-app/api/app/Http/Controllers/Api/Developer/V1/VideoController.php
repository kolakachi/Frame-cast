<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Events\GenerationProgressed;
use App\Models\ApiQuote;
use App\Models\Asset;
use App\Models\CreditLedgerEntry;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Projects\ProjectCreationException;
use App\Services\Projects\ProjectCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Create a video from a quote, watch it, fetch the file.
 *
 * Every new project auto-finishes (FinishGeneratedVideoJob queues the export
 * once generation is done), so the whole flow is create → poll → result. The
 * dashboard's states are folded into four the client can act on.
 */
class VideoController extends DeveloperController
{
    public function __construct(
        private readonly CreditService $credits,
        private readonly ProjectCreationService $creation,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = (int) $user->workspace_id;

        $input = $this->validated($request, [
            'quote_id' => ['required', 'string', 'max:32'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);
        $idempotencyKey = $input['idempotency_key'] ?? $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return $this->fail('idempotency_key_required',
                'Send an idempotency_key (or Idempotency-Key header) so a retried request cannot create a second video.', 422);
        }
        $idempotencyKey = mb_substr(trim($idempotencyKey), 0, 128);

        // Claim the quote first, in its own transaction, so a concurrent
        // retry sees it consumed. Creation happens after commit because it
        // dispatches a job that must find the committed project.
        $claim = DB::transaction(function () use ($input, $workspaceId, $idempotencyKey): array|JsonResponse {
            // The workspace row lock serialises claims, so the in-flight
            // count below cannot be raced past by two simultaneous creates.
            \App\Models\Workspace::query()->whereKey($workspaceId)->lockForUpdate()->first();

            $quote = ApiQuote::query()->whereKey($input['quote_id'])->where('workspace_id', $workspaceId)->lockForUpdate()->first();
            if (! $quote) {
                return $this->fail('quote_not_found', 'No such quote in this workspace. Request a new one.', 404);
            }

            if ($quote->consumed_at) {
                if ($quote->idempotency_key === $idempotencyKey && $quote->project_id) {
                    return ['replay' => Project::query()->whereKey($quote->project_id)->where('workspace_id', $workspaceId)->first(), 'quote' => $quote];
                }

                return $this->fail('quote_consumed', 'This quote has already been used. Request a new one.', 409);
            }

            if ($quote->isExpired()) {
                return $this->fail('quote_expired', 'This quote has expired. Request a new one and create within '.ApiQuote::TTL_MINUTES.' minutes.', 410);
            }

            $reused = ApiQuote::query()->where('workspace_id', $workspaceId)->where('idempotency_key', $idempotencyKey)->exists();
            if ($reused) {
                return $this->fail('idempotency_key_reused', 'This idempotency key was already used for a different quote.', 409);
            }

            // Rate limits bound requests; this bounds what is in flight,
            // which is what bounds how fast credits can go.
            $maxActive = (int) config('developer.limits.max_active_videos');
            $active = Project::query()->where('workspace_id', $workspaceId)->whereNotNull('api_key_id')->where('status', 'generating')->count();
            if ($maxActive > 0 && $active >= $maxActive) {
                return $this->fail('too_many_active_videos',
                    "{$active} API-created videos are already generating in this workspace (limit {$maxActive}). Wait for one to finish.",
                    429, ['active' => $active, 'limit' => $maxActive, 'retry_after_seconds' => 30]);
            }

            $balance = $this->credits->balance($workspaceId);
            if ($balance < $quote->credits_max) {
                return $this->fail('insufficient_credits',
                    "This video may cost up to {$quote->credits_max} credits; the balance is {$balance}.",
                    402, ['balance' => $balance, 'authorized_max' => $quote->credits_max, 'shortage' => $quote->credits_max - $balance]);
            }

            $quote->forceFill(['consumed_at' => now(), 'idempotency_key' => $idempotencyKey])->save();

            return ['quote' => $quote];
        });

        if ($claim instanceof JsonResponse) {
            return $claim;
        }
        /** @var ApiQuote $quote */
        $quote = $claim['quote'];
        if (isset($claim['replay'])) {
            return $claim['replay'] ? $this->created($claim['replay'], $quote, 200) : $this->fail('not_found', 'Video not found.', 404);
        }

        try {
            ['project' => $project] = $this->creation->create($user, $quote->payload_json, $request->attributes->get('api_key_id'));
        } catch (ProjectCreationException $e) {
            // Give the quote back: nothing was built, nothing was spent.
            $quote->forceFill(['consumed_at' => null, 'idempotency_key' => null])->save();

            return $this->creationFailed($e);
        }

        $quote->forceFill(['project_id' => $project->getKey()])->save();

        return $this->created($project, $quote, 202);
    }

    public function show(Request $request, int $videoId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $project = $this->find($user, $videoId);
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }

        $latest = $this->latestExport($project);
        $status = $this->status($project, $latest);
        $progress = GenerationProgressed::getProgress((int) $project->getKey());
        $stages = [];
        foreach ((array) ($progress['stages'] ?? []) as $name => $stage) {
            $stages[$name] = $stage['status'] ?? null;
        }

        $failure = null;
        if ($status === 'failed') {
            $failure = [
                'message' => $latest?->status === 'failed' ? $latest->failure_reason : ($progress['last_message'] ?? 'Generation failed.'),
                'stage' => $latest?->status === 'failed' ? 'export' : ($progress['current_stage'] ?? null),
                // The dashboard offers retry-generation only for a failed
                // project; a failed export is retried from the dashboard.
                'retryable' => $project->status === 'failed',
            ];
        }

        return response()->json(['data' => ['video' => [
            'id' => $project->getKey(),
            'status' => $status,
            'title' => $project->title,
            'stage' => [
                'current' => $status === 'exporting' ? 'export' : ($progress['current_stage'] ?? null),
                'message' => $status === 'exporting' ? 'Rendering the final video.' : ($progress['last_message'] ?? null),
                'stages' => $stages,
            ],
            'failure' => $failure,
            'credits' => ['authorized_max' => $this->authorizedMax($project), 'spent' => $this->spent($project)],
            'retry_after_seconds' => in_array($status, ['completed', 'failed'], true) ? null : 15,
            'project_url' => $this->projectUrl($project),
        ]], 'meta' => []]);
    }

    public function result(Request $request, int $videoId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $project = $this->find($user, $videoId);
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }

        $export = ExportJob::query()->where('project_id', $project->getKey())->where('status', 'completed')->latest('id')->first();
        $asset = $export?->output_asset_id ? Asset::query()->whereKey($export->output_asset_id)->where('workspace_id', $project->workspace_id)->first() : null;
        if (! $export || ! $asset) {
            $status = $this->status($project, $this->latestExport($project));

            return $this->fail('not_ready', 'The video is not finished yet. Poll GET /videos/{id} until status is completed.', 409, ['status' => $status]);
        }

        $ttl = (int) config('media.signed_url_ttl_minutes', 720);
        $expires = now()->addMinutes($ttl);

        return response()->json(['data' => ['video' => [
            'id' => $project->getKey(),
            'status' => 'completed',
            'title' => $project->title,
            // The same signed, workspace-private link the dashboard uses.
            // Nothing is made public to hand it over.
            'download_url' => URL::temporarySignedRoute('media.assets.content', $expires, ['assetId' => $asset->getKey(), 'download' => 1]),
            'download_expires_at' => $expires->toIso8601String(),
            'file_name' => $export->file_name,
            'aspect_ratio' => $export->aspect_ratio,
            'duration_seconds' => $asset->duration_seconds !== null ? round((float) $asset->duration_seconds, 1) : null,
            'credits' => ['spent' => $this->spent($project)],
            'project_url' => $this->projectUrl($project),
        ]], 'meta' => []]);
    }

    private function created(Project $project, ApiQuote $quote, int $status): JsonResponse
    {
        return response()->json(['data' => ['video' => [
            'id' => $project->getKey(),
            'status' => $this->status($project, $this->latestExport($project)),
            'quote_id' => $quote->getKey(),
            'credits' => ['authorized_max' => $quote->credits_max],
            'project_url' => $this->projectUrl($project),
        ]], 'meta' => []], $status);
    }

    private function find(User $user, int $videoId): ?Project
    {
        return Project::query()->whereKey($videoId)->where('workspace_id', $user->workspace_id)->first();
    }

    private function latestExport(Project $project): ?ExportJob
    {
        return ExportJob::query()->where('project_id', $project->getKey())->latest('id')->first();
    }

    /**
     * generating → exporting → completed, or failed. "exporting" also covers
     * the gap between generation finishing and the export job being queued,
     * since the client can do nothing different during it.
     */
    private function status(Project $project, ?ExportJob $latest): string
    {
        if ($latest?->status === 'completed') {
            return 'completed';
        }
        if ($project->status === 'failed' || $latest?->status === 'failed') {
            return 'failed';
        }
        if ($project->status === 'ready_for_review') {
            return 'exporting';
        }

        return 'generating';
    }

    private function spent(Project $project): int
    {
        return (int) CreditLedgerEntry::query()->where('project_id', $project->getKey())->sum('credits');
    }

    private function authorizedMax(Project $project): ?int
    {
        return ApiQuote::query()->where('project_id', $project->getKey())->value('credits_max');
    }
}

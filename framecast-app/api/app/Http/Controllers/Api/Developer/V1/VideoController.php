<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Events\GenerationProgressed;
use App\Models\ApiKey;
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
    use ClaimsQuotes;

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
        $idempotencyKey = $this->idempotencyKeyFrom($request, $input);
        if ($idempotencyKey === null) {
            return $this->fail('idempotency_key_required',
                'Send an idempotency_key (or Idempotency-Key header) so a retried request cannot create a second video.', 422);
        }

        $apiKeyId = $request->attributes->get('api_key_id');
        $claim = $this->claimQuote((string) $input['quote_id'], $workspaceId, $idempotencyKey, $apiKeyId, 'video', $this->credits);

        if ($claim instanceof JsonResponse) {
            return $claim;
        }
        /** @var ApiQuote $quote */
        $quote = $claim['quote'];
        if (isset($claim['replay'])) {
            return $claim['replay'] ? $this->created($claim['replay'], $quote, 200) : $this->fail('not_found', 'Video not found.', 404);
        }

        $payload = $quote->payload_json;
        $musicAssetId = $payload['music_asset_id'] ?? null;
        unset($payload['music_asset_id'], $payload['__kind']);
        try {
            ['project' => $project] = $this->creation->create($user, $payload, $request->attributes->get('api_key_id'));
            if ($musicAssetId) {
                // Same shape the editor writes when a track is picked.
                $project->forceFill(['music_asset_id' => (int) $musicAssetId, 'music_settings_json' => ['volume' => 30, 'duck_volume' => 8, 'fade_in_ms' => 500, 'loop' => true, 'duck_during_voice' => true]])->save();
            }
        } catch (ProjectCreationException $e) {
            // Give the quote back: nothing was built, nothing was spent.
            $this->releaseQuote($quote);

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

<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Models\Project;
use App\Services\CreditService;
use App\Services\Developer\EditOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Delivery remains an explicit, authenticated app action. This never sends or publishes. */
class DeliveryController extends DeveloperController
{
    public const ACTIONS = ['public_share', 'approval_request', 'schedule'];

    public function handoff(Request $request, int $videoId): JsonResponse
    {
        $project = Project::query()->whereKey($videoId)->where('workspace_id', $request->user()->workspace_id)->first();
        if (! $project) return $this->fail('not_found', 'Video not found.', 404);
        $input = $this->validated($request, [
            'action' => ['required', 'in:'.implode(',', self::ACTIONS)],
            'revision' => ['required', 'string'],
            'export_id' => ['required', 'integer', 'min:1'],
            'allow_stale' => ['sometimes', 'boolean'],
        ]);
        if ($input['revision'] !== EditorController::revision($project)) return $this->fail('revision_conflict', 'The project changed. Read it again before choosing a delivery version.', 409);
        if ($input['action'] === 'schedule' && ! app(CreditService::class)->limitFor((int) $project->workspace_id, 'social_publishing')) return $this->fail('upgrade_required', 'Social publishing is not available on this plan.', 402);
        // The result endpoint already enforces project ownership, completed
        // output, latest-version selection and explicit stale-version consent.
        $checked = app(VideoController::class)->result(EditOperations::inner($request, [
            'export_id' => $input['export_id'], 'allow_stale' => $input['allow_stale'] ?? false,
        ], 'GET'), $videoId);
        if ($checked->getStatusCode() >= 400) return $checked;
        $video = $checked->getData(true)['data']['video'];
        return response()->json(['data' => self::describe($project, $input['action']) + [
            'reviewed_export_id' => $input['export_id'], 'reviewed_revision' => $input['revision'],
            'allow_stale' => $input['allow_stale'] ?? false, 'version_checked' => true,
            'source_fingerprint' => $video['source_fingerprint'] ?? null,
        ], 'meta' => []]);
    }

    public static function describe(Project $project, string $action): array
    {
        $requirements = match ($action) {
            'public_share' => ['Review the export version in the app.', 'Confirm that anyone with the public link may view it. The app share link may follow newer exports.'],
            'approval_request' => ['Review the export version in the app.', 'Enter and confirm the reviewer email, message and expiry before sending.'],
            default => ['Review the export version in the app.', 'Choose and confirm the social account, destination, content, date/time and timezone before posting or scheduling.'],
        };
        $label = match ($action) { 'public_share' => 'Share publicly', 'approval_request' => 'Send for approval', default => 'Schedule' };
        return [
            'outcome' => 'handoff_required', 'action' => $action,
            'external_action_completed' => false,
            'app_url' => rtrim((string) config('app.frontend_url'), '/').'/projects/'.$project->id.'/editor',
            'next_step' => "Open the editor and choose {$label}. No delivery action has occurred.",
            'confirmation_required_in_app' => $requirements,
            'revalidate_in_app' => true,
            'version_binding' => 'Preflight only; the app does not automatically select or pin this export. Verify the version again before confirming.',
        ];
    }
}

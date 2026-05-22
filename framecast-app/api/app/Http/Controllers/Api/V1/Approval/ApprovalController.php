<?php

namespace App\Http\Controllers\Api\V1\Approval;

use App\Http\Controllers\Controller;
use App\Mail\ApprovalRequestMail;
use App\Models\Approval;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApprovalController extends Controller
{
    // ── Workspace: list & create ─────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $approvals = Approval::query()
            ->where('workspace_id', $user->workspace_id)
            ->with('project:id,title')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => [
                'approvals' => $approvals->map(fn (Approval $a) => $this->serialize($a))->all(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'project_id'     => ['required', 'integer'],
            'export_job_id'  => ['nullable', 'integer'],
            'reviewer_email' => ['required', 'email:rfc'],
            'reviewer_name'  => ['nullable', 'string', 'max:160'],
            'comment'        => ['nullable', 'string', 'max:1000'],
            'expires_in_days'=> ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $project = Project::query()
            ->where('workspace_id', $user->workspace_id)
            ->find($validated['project_id']);

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        if (! empty($validated['export_job_id'])) {
            $exportJob = ExportJob::query()
                ->where('workspace_id', $user->workspace_id)
                ->where('project_id', $project->id)
                ->find($validated['export_job_id']);
            if (! $exportJob) {
                return $this->error('not_found', 'Export not found.', 404);
            }
        }

        $approval = Approval::query()->create([
            'token'                => Str::random(64),
            'workspace_id'         => $user->workspace_id,
            'project_id'           => $project->id,
            'export_job_id'        => $validated['export_job_id'] ?? null,
            'requested_by_user_id' => $user->id,
            'reviewer_email'       => $validated['reviewer_email'],
            'reviewer_name'        => $validated['reviewer_name'] ?? null,
            'status'               => 'pending',
            'comment'              => $validated['comment'] ?? null,
            'expires_at'           => now()->addDays($validated['expires_in_days'] ?? 7),
            'metadata_json'        => ['project_title' => $project->title],
        ]);

        $publicUrl = $this->publicApprovalUrl($approval->token);

        try {
            Mail::to($approval->reviewer_email)
                ->send(new ApprovalRequestMail($approval, $project, $publicUrl, $user));
        } catch (\Throwable $e) {
            // Don't fail the whole request if email is undeliverable — return the link
            return response()->json([
                'data'    => ['approval' => $this->serialize($approval), 'public_url' => $publicUrl],
                'warning' => 'Approval link created but email could not be sent. Share the link manually.',
            ], 201);
        }

        return response()->json([
            'data' => ['approval' => $this->serialize($approval), 'public_url' => $publicUrl],
        ], 201);
    }

    public function revoke(Request $request, int $approvalId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $approval = Approval::query()
            ->where('workspace_id', $user->workspace_id)
            ->find($approvalId);

        if (! $approval) {
            return $this->error('not_found', 'Approval not found.', 404);
        }

        if (in_array($approval->status, ['approved', 'rejected'], true)) {
            return $this->error('already_decided', 'This approval has already been reviewed.', 422);
        }

        $approval->update(['status' => 'cancelled', 'expires_at' => now()]);

        return response()->json(['data' => ['approval' => $this->serialize($approval)]]);
    }

    // ── Public (no auth): fetch & decide ─────────────────────────────────────

    public function publicShow(string $token): JsonResponse
    {
        $approval = Approval::query()
            ->with(['project:id,title,script_text,workspace_id', 'exportJob:id,project_id,file_name,output_asset_id,aspect_ratio,language'])
            ->where('token', $token)
            ->first();

        if (! $approval) {
            return $this->error('not_found', 'Approval link is invalid or has been deleted.', 404);
        }

        if ($approval->isExpired() || $approval->status === 'cancelled') {
            return response()->json([
                'data' => ['approval' => $this->publicSerialize($approval), 'expired' => true],
            ]);
        }

        return response()->json([
            'data' => ['approval' => $this->publicSerialize($approval)],
        ]);
    }

    public function publicDecide(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'comment'  => ['nullable', 'string', 'max:2000'],
            'reviewer_name' => ['nullable', 'string', 'max:160'],
        ]);

        $approval = Approval::query()->where('token', $token)->first();
        if (! $approval) {
            return $this->error('not_found', 'Approval link is invalid.', 404);
        }
        if ($approval->isExpired()) {
            return $this->error('expired', 'This approval link has expired.', 410);
        }
        if (in_array($approval->status, ['approved', 'rejected'], true)) {
            return $this->error('already_decided', 'This approval has already been reviewed.', 422);
        }

        $approval->update([
            'status'        => $validated['decision'],
            'comment'       => $validated['comment'] ?? null,
            'reviewer_name' => $validated['reviewer_name'] ?? $approval->reviewer_name,
            'reviewed_at'   => now(),
        ]);

        // Notify the workspace
        rescue(function () use ($approval, $validated): void {
            WorkspaceNotification::query()->create([
                'workspace_id' => $approval->workspace_id,
                'user_id'      => $approval->requested_by_user_id,
                'title'        => $validated['decision'] === 'approved' ? 'Post approved' : 'Post rejected',
                'message'      => sprintf(
                    '%s %s your video "%s".%s',
                    $approval->reviewer_name ?? $approval->reviewer_email,
                    $validated['decision'] === 'approved' ? 'approved' : 'rejected',
                    $approval->project?->title ?? 'Project',
                    isset($validated['comment']) && $validated['comment'] !== '' ? ' Note: '.$validated['comment'] : '',
                ),
                'type'         => $validated['decision'] === 'approved' ? 'success' : 'error',
                'metadata_json'=> [
                    'approval_id' => $approval->id,
                    'project_id'  => $approval->project_id,
                ],
            ]);
        });

        return response()->json(['data' => ['approval' => $this->publicSerialize($approval)]]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function publicApprovalUrl(string $token): string
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        return $frontend . '/approve/' . $token;
    }

    private function serialize(Approval $a): array
    {
        return [
            'id'             => $a->id,
            'project_id'     => $a->project_id,
            'project_title'  => $a->project?->title ?? $a->metadata_json['project_title'] ?? null,
            'export_job_id'  => $a->export_job_id,
            'reviewer_email' => $a->reviewer_email,
            'reviewer_name'  => $a->reviewer_name,
            'status'         => $a->status,
            'comment'        => $a->comment,
            'reviewed_at'    => $a->reviewed_at?->toIso8601String(),
            'expires_at'     => $a->expires_at?->toIso8601String(),
            'created_at'     => $a->created_at?->toIso8601String(),
            'public_url'     => $a->token ? $this->publicApprovalUrl($a->token) : null,
            'is_expired'     => $a->isExpired(),
        ];
    }

    private function publicSerialize(Approval $a): array
    {
        // Don't leak internals — only what the reviewer needs
        $project = $a->project;
        $exportJob = $a->exportJob;
        $outputAssetUrl = null;

        if ($exportJob && $exportJob->output_asset_id) {
            // Use the existing signed asset route
            $outputAssetUrl = URL::temporarySignedRoute(
                'media.assets.content',
                now()->addDays(7),
                ['assetId' => $exportJob->output_asset_id],
            );
        }

        return [
            'token'          => $a->token,
            'status'         => $a->status,
            'project_title'  => $project?->title ?? $a->metadata_json['project_title'] ?? 'Untitled project',
            'project_script' => $project?->script_text ? mb_substr($project->script_text, 0, 4000) : null,
            'video_url'      => $outputAssetUrl,
            'video_filename' => $exportJob?->file_name,
            'reviewer_email' => $a->reviewer_email,
            'reviewer_name'  => $a->reviewer_name,
            'comment'        => $a->comment,
            'reviewed_at'    => $a->reviewed_at?->toIso8601String(),
            'expires_at'     => $a->expires_at?->toIso8601String(),
            'is_expired'     => $a->isExpired(),
            'requester_message' => $a->status === 'pending' ? ($a->metadata_json['original_comment'] ?? null) : null,
        ];
    }
}

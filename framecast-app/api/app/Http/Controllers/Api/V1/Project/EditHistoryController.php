<?php

namespace App\Http\Controllers\Api\V1\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Editor\EditHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Undo and redo in the Classic editor (EditHistory). */
class EditHistoryController extends Controller
{
    public function show(Request $request, int $projectId): JsonResponse
    {
        if (! $this->project($request, $projectId)) return $this->error('not_found', 'Project not found.', 404);
        return response()->json(['data' => EditHistory::status($projectId), 'meta' => []]);
    }

    public function undo(Request $request, int $projectId): JsonResponse
    {
        return $this->step($request, $projectId, fn () => EditHistory::undo($projectId, $this->expect($request)), 'undone');
    }

    public function redo(Request $request, int $projectId): JsonResponse
    {
        return $this->step($request, $projectId, fn () => EditHistory::redo($projectId, $this->expect($request)), 'redone');
    }

    private function step(Request $request, int $projectId, \Closure $do, string $key): JsonResponse
    {
        if (! $this->project($request, $projectId)) return $this->error('not_found', 'Project not found.', 404);
        try {
            $label = $do();
        } catch (\DomainException $e) {
            return $this->error('history_unavailable', $e->getMessage(), 409);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            return $this->error('history_unavailable', 'That step could not be put back. Nothing was changed.', 409);
        }
        return response()->json(['data' => [$key => $label] + EditHistory::status($projectId), 'meta' => []]);
    }

    /** The step the caller showed the user (edit_id from the history): refused if another is next by now. */
    private function expect(Request $request): ?int
    {
        $id = $request->input('edit_id');
        return is_scalar($id) && ctype_digit((string) $id) ? (int) $id : null;
    }

    private function project(Request $request, int $projectId): ?Project
    {
        return Project::query()->whereKey($projectId)->where('workspace_id', $request->user()->workspace_id)->first();
    }
}

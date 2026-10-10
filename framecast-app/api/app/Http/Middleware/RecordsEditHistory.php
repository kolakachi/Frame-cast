<?php

namespace App\Http\Middleware;

use App\Services\Editor\EditHistory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps the editor's writing requests (scenes, project settings, bulk actions, the in-editor assistant, the
 * assistants' applied plans): snapshot the project before and after, and record what changed as one undoable edit.
 */
class RecordsEditHistory
{
    /** Plain words for each editor action; {n} is the scene's number. */
    private const LABELS = [
        'SceneController@store' => 'Added a scene', 'SceneController@update' => 'Edited scene {n}', 'SceneController@reorder' => 'Reordered scenes',
        'SceneController@destroy' => 'Deleted scene {n}', 'SceneController@duplicate' => 'Duplicated scene {n}', 'SceneController@swapVisual' => "Changed scene {n}'s picture",
        'SceneController@generateImage' => 'Generated a new picture for scene {n}', 'SceneController@editImage' => "Edited scene {n}'s picture",
        'SceneController@animate' => 'Animated scene {n}', 'SceneController@revertAnimation' => 'Put scene {n} back to its still',
        'SceneController@cancelAnimation' => "Cancelled scene {n}'s animation", 'SceneController@useAnimationFromHistory' => 'Picked an earlier clip for scene {n}',
        'SceneController@regenerateVoice' => "Re-recorded scene {n}'s voice", 'SceneController@regenerateMusic' => 'Regenerated the music',
        'SceneController@rewrite' => 'Rewrote scene {n}', 'ProjectController@update' => 'Changed the video settings',
        'ProjectController@resumeFailed' => 'Resumed failed scenes', 'ProjectController@retryGeneration' => 'Retried generation',
        'BulkAnimateController' => 'Animated every scene', 'BulkVisualController' => 'Restyled every scene', 'BulkVoiceController' => 'Re-recorded every voice',
        'CruiseControlController@apply' => 'Assistant edit', 'CruiseControlController@undo' => "Undid an assistant's action",
        'EditorController@apply' => 'Assistant edit', 'AssistantController@apply' => "Applied the assistant's plan",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // The assistant's own undo/redo steps move through the history; recording them would end the redo trail.
        $historyStep = in_array($request->input('tool'), ['undo_last_edit', 'redo_last_edit'], true);
        $projectId = $request->isMethod('GET') || $historyStep ? null : $this->projectId($request);
        if (! $projectId || ! \Illuminate\Support\Facades\Schema::hasTable('project_edits')) return $next($request);
        $before = EditHistory::snapshot($projectId);
        $response = $next($request);
        try {
            $action = class_basename((string) $request->route()?->getActionName());
            $sceneId = (int) ($request->route('sceneId') ?? $request->input('scene_id') ?? data_get($request->input('params'), 'scene_id') ?? 0);
            $n = $sceneId && isset($before['scenes'][$sceneId]) ? $before['scenes'][$sceneId]['scene_order'] : null;
            $label = self::LABELS[$action] ?? self::LABELS[strtok($action, '@')] ?? 'Edited the video';
            if ($action === 'CruiseControlController@apply' && $request->input('tool')) $label = 'Assistant: '.str_replace('_', ' ', (string) $request->input('tool'));
            $label = $n ? str_replace('{n}', (string) $n, $label) : str_replace([' {n}', "{n}'s"], ['', "a scene's"], $label);
            $actor = str_starts_with($request->path(), 'api/developer/') ? 'assistant' : (str_contains($action, 'CruiseControlController') ? 'cruise' : 'user');
            EditHistory::record($projectId, $before, $label, $actor, $request->user()?->getKey());
        } catch (\Throwable $e) {
            report($e); // the edit itself stands; only its history entry is lost
        }
        return $response;
    }

    private function projectId(Request $request): ?int
    {
        $id = $request->route('projectId') ?? $request->route('videoId') ?? $request->input('project_id');
        if (! $id && ($sceneId = $request->route('sceneId'))) $id = DB::table('scenes')->where('id', (int) $sceneId)->value('project_id');
        if (! $id) return null;
        // Classic projects only: a Weave video (editor_kind composition) has versions of its own.
        return DB::table('projects')->where('id', (int) $id)->value('editor_kind') === 'composition' ? null : (int) $id;
    }
}

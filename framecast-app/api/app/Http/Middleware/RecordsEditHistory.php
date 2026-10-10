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

    /** Generating: the result comes later from a job, so these are not undoable steps. */
    private const GENERATING = ['SceneController@generateDraft', 'SceneController@generateImage', 'SceneController@editImage', 'SceneController@animate',
        'SceneController@cancelAnimation', 'SceneController@regenerateVoice', 'SceneController@regenerateMusic', 'BulkAnimateController',
        'BulkVisualController', 'BulkVoiceController', 'ProjectController@resumeFailed', 'ProjectController@retryGeneration'];
    private const GENERATING_TOOLS = ['regenerate_image', 'animate_scene', 'make_spokesperson', 'rerecord_voice', 'change_music', 'export_video',
        'schedule_post', 'undo_last_edit', 'redo_last_edit'];
    /** Edits that change only the scene they name: only that scene is compared, so another scene's job result is never swept in. */
    private const ONE_SCENE = ['SceneController@update', 'SceneController@swapVisual', 'SceneController@revertAnimation',
        'SceneController@useAnimationFromHistory', 'SceneController@rewrite'];

    public function handle(Request $request, Closure $next): Response
    {
        $action = class_basename((string) $request->route()?->getActionName());
        $tool = is_string($request->input('tool')) ? $request->input('tool') : null;
        if ($request->isMethod('GET') || in_array($action, self::GENERATING, true) || in_array(strtok($action, '@'), self::GENERATING, true)
            || in_array($tool, self::GENERATING_TOOLS, true)) {
            return $next($request);
        }
        $projectId = $this->projectId($request);
        if (! $projectId) return $next($request);
        $sceneId = (int) ($request->route('sceneId') ?? 0);
        $scope = in_array($action, self::ONE_SCENE, true) && $sceneId ? [$sceneId] : null;
        $before = EditHistory::snapshot($projectId, $scope);
        $response = $next($request);
        try {
            if ($response->getStatusCode() >= 400) return $response;   // a refused request changed nothing worth a step
            $paramScene = data_get($request->input('params'), 'scene_id');
            $n = null;
            foreach ([$sceneId, is_scalar($request->input('scene_id')) ? (int) $request->input('scene_id') : 0, is_scalar($paramScene) ? (int) $paramScene : 0] as $id) {
                if ($id && isset($before['scenes'][$id])) { $n = $before['scenes'][$id]['scene_order']; break; }
            }
            $label = self::LABELS[$action] ?? self::LABELS[strtok($action, '@')] ?? 'Edited the video';
            if ($action === 'CruiseControlController@apply' && $tool) $label = 'Assistant: '.str_replace('_', ' ', $tool);
            $label = $n ? str_replace('{n}', (string) $n, $label) : str_replace([' {n}', "{n}'s"], ['', "a scene's"], $label);
            $actor = str_starts_with($request->path(), 'api/developer/') ? 'assistant' : (str_contains($action, 'CruiseControlController') ? 'cruise' : 'user');
            EditHistory::record($projectId, $before, $label, $actor, $request->user()?->getKey(), $scope);
        } catch (\Throwable $e) {
            report($e); // the edit itself stands; only its history step is lost
        }
        return $response;
    }

    /** The project this request edits, if it is a Classic project in the requester's own workspace. */
    private function projectId(Request $request): ?int
    {
        $raw = $request->route('projectId') ?? $request->route('videoId') ?? $request->input('project_id');
        $id = is_scalar($raw) && ctype_digit((string) $raw) ? (int) $raw : null;
        if (! $id && ($sceneId = $request->route('sceneId'))) $id = (int) DB::table('scenes')->where('id', (int) $sceneId)->value('project_id');
        $workspaceId = $request->user()?->workspace_id;
        if (! $id || ! $workspaceId) return null;
        $project = DB::table('projects')->where('id', $id)->where('workspace_id', $workspaceId)->first(['id', 'editor_kind']);
        // Classic projects only: a Weave video (editor_kind composition) has versions of its own.
        return $project && ($project->editor_kind ?? null) !== 'composition' ? (int) $project->id : null;
    }
}

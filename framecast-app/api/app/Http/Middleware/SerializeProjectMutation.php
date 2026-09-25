<?php

namespace App\Http\Middleware;

use App\Models\Scene;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** PostgreSQL session lock covers revision check through mutation/dispatch.
 * Database triggers use the same key for writes from workers and other routes.
 * A dead process releases the session lock; no TTL may expire during a render.
 */
class SerializeProjectMutation
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->isMethodSafe() || DB::connection()->getDriverName() !== 'pgsql') return $next($request);
        $id = (int) ($request->route('videoId') ?? $request->route('projectId') ?? $request->input('project_id', 0));
        if (! $id && $request->route('sceneId')) {
            $id = (int) Scene::query()->whereKey($request->route('sceneId'))->value('project_id');
        }
        if (! $id) return $next($request);
        $locked = DB::selectOne('select pg_try_advisory_lock(198734, ?) as acquired', [$id])->acquired;
        if (! $locked) return response()->json(['error' => ['code' => 'project_busy', 'message' => 'This project is being updated. Wait, read its current state and try again.']], 409);
        try {
            return $next($request);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() !== '55P03') throw $e;
            return response()->json(['error' => ['code' => 'project_busy', 'message' => 'The project changed concurrently. Read its state before retrying.']], 409);
        } finally {
            DB::select('select pg_advisory_unlock(198734, ?)', [$id]);
        }
    }
}

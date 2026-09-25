<?php

namespace App\Http\Middleware;

use App\Models\Scene;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Serialises developer-API mutations of one project: a PostgreSQL session
 * advisory lock is held from the revision check through mutation and
 * dispatch, so two API requests cannot apply against the same state.
 *
 * Deliberately API-only. An earlier version paired this with database
 * triggers that rejected any other writer of the project's rows — dashboard
 * autosaves and queue workers included — with an immediate error. Three
 * workers and an editor write the same project concurrently as a matter of
 * course, so that would have surfaced as random generation failures. Those
 * writers are not fenced; the content-fingerprint revision detects them at
 * apply time, and the remaining window between check and apply is narrow
 * and recorded in the backlog (A4).
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

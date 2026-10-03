<?php
namespace App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Controller;
use App\Services\Create\TrajectoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class CreateTrajectoryController extends Controller
{
    public function index(Request $r) {
        $v = $r->validate(['q' => 'nullable|string|max:160', 'page' => 'sometimes|integer|min:1']);
        $q = DB::table('create_conversations')->select(['id', 'title', 'workspace_id', 'updated_at']);
        if ($search = trim($v['q'] ?? '')) $q->where(function ($q) use ($search) {
            $q->where('title', 'like', '%'.$search.'%');
            if (\Illuminate\Support\Str::isUuid($search)) $q->orWhere('id', $search)->orWhereIn('id', DB::table('composition_runs')->select('conversation_id')->where('id', $search));
        });
        $page = $q->orderByDesc('updated_at')->paginate(25);
        $page->getCollection()->transform(function ($row) { $row->title = TrajectoryService::safe($row->title); return $row; });
        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }
    public function show(string $id) {
        return response()->json(['data' => app(TrajectoryService::class)->show($id)])->header('Cache-Control', 'private, no-store');
    }
}

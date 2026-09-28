<?php

namespace App\Http\Controllers\Api\V1\Create;

use App\Http\Controllers\Controller;
use App\Services\Create\RunService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WorkerController extends Controller
{
    public function __construct(private RunService $runs) {}

    private function authorizeWorker(Request $r): void
    {
        abort_unless(app()->environment(['local', 'testing']) && config('create.enabled'), 404);
        $token = (string) config('create.worker_token');
        abort_unless(strlen($token) >= 32 && hash_equals($token, (string) $r->bearerToken()), 403);
    }

    public function claim(Request $r)
    {
        $this->authorizeWorker($r);
        return response()->json(['data' => $this->runs->claim()]);
    }

    public function heartbeat(Request $r, string $id)
    {
        $this->authorizeWorker($r);
        $input = $r->validate(['lease_token' => 'required|string|size:64', 'sequence' => 'required|integer|min:1', 'stage' => 'required|string|max:200']);
        return response()->json(['data' => $this->runs->heartbeat($id, $input['lease_token'], $input['sequence'], $input['stage'])]);
    }

    public function inputFile(Request $r, string $id, int $assetId)
    {
        $this->authorizeWorker($r);
        $input = $r->validate(['lease_token' => 'required|string|size:64']);
        $file = $this->runs->inputFile($id, $input['lease_token'], $assetId);
        return response()->file(Storage::disk('local')->path($file['storage_path']), [
            'Content-Type' => $file['mime_type'], 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function beginAttempt(Request $r, string $id)
    {
        $this->authorizeWorker($r);
        $input = $r->validate(['lease_token' => 'required|string|size:64', 'attempt_key' => 'required|string|max:100',
            'kind' => 'required|in:agent,media,render', 'request_hash' => 'required|regex:/^[a-f0-9]{64}$/']);
        return response()->json(['data' => app(\App\Services\Create\AttemptService::class)->begin($id, $input['lease_token'], $input['attempt_key'], $input['kind'], $input['request_hash'])]);
    }

    public function settleAttempt(Request $r, string $id, string $attemptId)
    {
        $this->authorizeWorker($r);
        $input = $r->validate(['lease_token' => 'required|string|size:64', 'status' => 'required|in:succeeded,failed,unknown',
            'prediction_id' => 'nullable|string|max:160', 'cost_microusd' => 'nullable|integer|min:0']);
        if (isset($input['cost_microusd'])) $input['cost_microusd'] = (int) $input['cost_microusd'];
        return response()->json(['data' => app(\App\Services\Create\AttemptService::class)->settle($id, $input['lease_token'], $attemptId, $input)]);
    }

    public function finish(Request $r, string $id)
    {
        $this->authorizeWorker($r);
        $input = $r->validate(['lease_token' => 'required|string|size:64', 'result' => 'required|json', 'artifact' => 'nullable|file|max:102400|mimetypes:video/mp4']);
        $result = json_decode($input['result'], true);
        abort_unless(is_array($result), 422, 'Expected a result object.');
        validator($result, ['status' => 'required|in:preview_ready,failed,cancelled,needs_attention', 'summary' => 'required|string|max:2000', 'bundle' => 'required_if:status,preview_ready|array|max:30'])->validate();
        $this->runs->validateResultLease($id, $input['lease_token']);
        $path = $hash = null;
        if ($r->hasFile('artifact')) {
            abort_unless(preg_match('/^[a-f0-9-]{36}$/D', $id), 422);
            $hash = hash_file('sha256', $r->file('artifact')->getRealPath());
            $path = 'create/previews/'.$id.'/'.$hash.'.mp4';
            // Content-addressed, private, never overwritten with a different file.
            if (! Storage::disk('local')->exists($path)) $r->file('artifact')->storeAs(dirname($path), basename($path), 'local');
        }
        return response()->json(['data' => $this->runs->finish($id, $input['lease_token'], $result, $path, $hash)]);
    }
}

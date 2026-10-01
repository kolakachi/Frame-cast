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

    public function derived(Request $r, string $id)
    {
        $this->authorizeWorker($r);
        $input = $r->validate(['lease_token' => 'required|string|size:64', 'file' => 'required|file|max:102400',
            'derived_from_asset_id' => 'required|integer|min:1', 'operation' => 'required|string|max:32', 'params' => 'sometimes|string|max:2000']);
        $params = json_decode($input['params'] ?? '{}', true);
        return response()->json(['data' => $this->runs->derived($id, $input['lease_token'], $r->file('file'), (int) $input['derived_from_asset_id'], $input['operation'], is_array($params) ? $params : [])], 201);
    }

    public function beginAttempt(Request $r, string $id)
    {
        $this->authorizeWorker($r);
        $input = $r->validate(['lease_token' => 'required|string|size:64', 'attempt_key' => 'required|string|max:100',
            'kind' => 'required|in:agent,media,render', 'request_hash' => 'required|regex:/^[a-f0-9]{64}$/']);
        return response()->json(['data' => app(\App\Services\Create\AttemptService::class)->begin($id, $input['lease_token'], $input['attempt_key'], $input['kind'], $input['request_hash'])]);
    }

    public function bindPrediction(Request $r, string $id, string $attemptId)
    {
        $this->authorizeWorker($r);
        $input=$r->validate(['lease_token'=>'required|string|size:64','prediction_id'=>'required|regex:/^[a-zA-Z0-9_-]{1,160}$/']);
        app(\App\Services\Create\AttemptService::class)->bindPrediction($id,$input['lease_token'],$attemptId,$input['prediction_id']);
        return response()->json(['data'=>['recorded'=>true]]);
    }

    public function settleAttempt(Request $r, string $id, string $attemptId)
    {
        $this->authorizeWorker($r);
        $input = $r->validate(['lease_token' => 'required|string|size:64', 'status' => 'required|in:succeeded,failed,unknown',
            'prediction_id' => 'nullable|string|max:160', 'cost_microusd' => 'nullable|integer|min:0']);
        if (isset($input['cost_microusd'])) $input['cost_microusd'] = (int) $input['cost_microusd'];
        $verified=null;
        if($input['status']!=='unknown') {
            $attempt=\Illuminate\Support\Facades\DB::table('composition_attempts')->where('run_id',$id)->where('id',$attemptId)->firstOrFail();
            if($attempt->provider==='anthropic') {
                // Settled by the gateway when it made the call; report that record.
                abort_unless($attempt->result_hash, 409, 'This call was not completed through the gateway.');
                return response()->json(['data'=>['status'=>$attempt->status,'charged_credits'=>(int)$attempt->charged_credits,'cost_microusd'=>$attempt->cost_microusd===null?null:(int)$attempt->cost_microusd,'prediction_id'=>$attempt->prediction_id,'replayed'=>true]]);
            }
            if($attempt->provider!=='offline') {
                $this->runs->validateResultLease($id,$input['lease_token']);
                $verified=app(\App\Services\Create\ProviderReceiptVerifier::class)->metered($attempt);
                $input=array_merge($input,$verified->result());
            }
        }
        return response()->json(['data' => app(\App\Services\Create\AttemptService::class)->settle($id, $input['lease_token'], $attemptId, $input,$verified)]);
    }

    public function planMedia(Request $r, string $id, int $index)
    {
        $this->authorizeWorker($r);
        $input = $r->validate(['lease_token' => 'required|string|size:64']);
        // Generation can take minutes (animation especially).
        set_time_limit(420);
        return response()->json(['data' => app(\App\Services\Create\PlanMediaService::class)->produce($id, $input['lease_token'], $index)]);
    }

    /** The agent buys a catalogue item mid-build, within the approved ceiling. */
    public function planMediaAdHoc(Request $r, string $id)
    {
        $this->authorizeWorker($r);
        $input = $r->validate(['lease_token' => 'required|string|size:64', 'kind' => 'required|string|max:40', 'description' => 'required|string|max:200']);
        set_time_limit(420);
        return response()->json(['data' => app(\App\Services\Create\PlanMediaService::class)->produceAdHoc($id, $input['lease_token'], $input['kind'], $input['description'])]);
    }

    public function transcript(Request $r, string $id)
    {
        $this->authorizeWorker($r);
        $input = $r->validate(['lease_token' => 'required|string|size:64', 'asset_id' => 'required|integer|min:1']);
        return response()->json(['data' => app(\App\Services\Create\TranscriptService::class)->forRun($id, $input['lease_token'], (int) $input['asset_id'])]);
    }

    public function anthropic(Request $r, string $id, string $attemptId)
    {
        $this->authorizeWorker($r);
        $input = $r->validate(['lease_token' => 'required|string|size:64', 'prompt' => 'required|string|max:200000', 'system' => 'required|string|max:200000',
            'max_tokens' => 'required|integer|min:256|max:16384', 'image' => 'nullable|string|max:1500000',
            // Tool mode: the conversation so far and the tools on offer, as the exact JSON strings the worker hashed.
            'messages_json' => 'nullable|string|max:6000000', 'tools_json' => 'nullable|string|max:60000']);
        // The recorded hash covers the exact text; the global string trimming must not alter it.
        $raw = json_decode($r->getContent(), true, 32, JSON_THROW_ON_ERROR);
        foreach (['prompt', 'system', 'image', 'messages_json', 'tools_json'] as $k) $input[$k] = $raw[$k] ?? null;
        abort_unless(is_string($input['prompt']) && is_string($input['system']), 422);
        return response()->json(['data' => app(\App\Services\Create\AnthropicGateway::class)->complete($id, $input['lease_token'], $attemptId, $input)]);
    }

    public function finish(Request $r, string $id)
    {
        $this->authorizeWorker($r);
        $input = $r->validate(['lease_token' => 'required|string|size:64', 'result' => 'required|json', 'artifact' => 'nullable|file|max:102400|mimetypes:video/mp4,image/png,image/jpeg,image/webp']);
        $result = json_decode($input['result'], true);
        abort_unless(is_array($result), 422, 'Expected a result object.');
        validator($result, ['status' => 'required|in:preview_ready,failed,cancelled,needs_attention,needs_input', 'summary' => 'required|string|max:2000', 'bundle' => 'required_if:status,preview_ready|array|max:30'])->validate();
        $this->runs->validateResultLease($id, $input['lease_token']);
        $path = $hash = null;
        if ($r->hasFile('artifact')) {
            abort_unless(preg_match('/^[a-f0-9-]{36}$/D', $id), 422);
            $hash = hash_file('sha256', $r->file('artifact')->getRealPath());
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($r->file('artifact')->getRealPath());
            $extension=match($mime) {'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','video/mp4'=>'mp4',default=>abort(422)};
            $run=\Illuminate\Support\Facades\DB::table('composition_runs')->where('id',$id)->firstOrFail();
            $settings=json_decode($run->input_json,true)['settings'];
            abort_unless((($settings['output_kind']??'video')==='image')===($extension!=='mp4'),422,'Output type does not match the approved plan.');
            if($extension==='mp4') {
                $probe=new \Symfony\Component\Process\Process(['ffprobe','-v','error','-show_streams','-show_format','-of','json',$r->file('artifact')->getRealPath()]);$probe->setTimeout(20);$probe->mustRun();$info=json_decode($probe->getOutput(),true);
                $video=collect($info['streams']??[])->firstWhere('codec_type','video');$duration=(float)($info['format']['duration']??0);
                $fixture=json_decode($run->input_json,true)['mode']==='fixture';
                abort_unless($video && $video['width']<=4096 && $video['height']<=4096 && abs($duration-($fixture?15:$settings['duration_seconds']))<=.3,422,'Encoded video does not match the approved duration.');
                if(($settings['video_mode']??'composition')==='animate_image') abort_if(collect($info['streams'])->contains('codec_type','audio'),422,'Animation must be delivered without generated audio.');
                $result['media']=['kind'=>'video','mime_type'=>$mime,'width'=>$video['width'],'height'=>$video['height'],'duration_seconds'=>$duration];
            }
            if($extension!=='mp4') {
                $size=getimagesize($r->file('artifact')->getRealPath());
                abort_unless($size && $size[0]<=8192 && $size[1]<=8192,422,'Invalid output image.');
                $result['media']=['kind'=>'image','mime_type'=>$mime,'width'=>$size[0],'height'=>$size[1]];
            }
            $path = 'create/previews/'.$id.'/'.$hash.'.'.$extension;
            // Content-addressed, private, never overwritten with a different file.
            if (! Storage::disk('local')->exists($path)) $r->file('artifact')->storeAs(dirname($path), basename($path), 'local');
        }
        return response()->json(['data' => $this->runs->finish($id, $input['lease_token'], $result, $path, $hash)]);
    }
}

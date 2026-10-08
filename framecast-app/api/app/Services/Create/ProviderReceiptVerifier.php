<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\Http;

class ProviderReceiptVerifier
{
    /** Local pilot only: independently verify metering; this is a tariff estimate, not an invoice. */
    public function metered(object $attempt): VerifiedAttemptReceipt
    {
        abort_unless(PilotPolicy::enabled() && $attempt->provider==='replicate',503);
        abort_unless(preg_match('/^[a-zA-Z0-9_-]{1,160}$/D',$attempt->prediction_id??''),409,'Prediction identity missing.');
        $p=Http::withToken(config('services.replicate.api_token'))->acceptJson()->timeout(20)->withOptions(['allow_redirects'=>false])
            ->get('https://api.replicate.com/v1/predictions/'.$attempt->prediction_id)->throw()->json();
        abort_unless(($p['id']??null)===$attempt->prediction_id && ($p['model']??null)===$attempt->model && ($p['status']??null)==='succeeded',409,'Provider success is not confirmed. Hold retained.');
        $input=$p['input']??[];
        if($attempt->model==='anthropic/claude-4.5-sonnet') {
            $canonical=['prompt'=>$input['prompt']??null,'system'=>$input['system_prompt']??null,'maxTokens'=>$input['max_tokens']??null,'image'=>$input['image']??null];
            $i=$p['metrics']['token_input_count']??null;$o=$p['metrics']['token_output_count']??null;
            abort_unless(is_int($i)&&$i>=0&&is_int($o)&&$o>=0,409,'Provider token metering missing. Hold retained.');
            $cost=$i*3+$o*15;
        } elseif($attempt->model==='google/nano-banana') {
            $canonical=['prompt'=>$input['prompt']??null,'output_format'=>$input['output_format']??null,'aspect_ratio'=>$input['aspect_ratio']??null];
            if(isset($input['image_input'])) $canonical['image_input']=$input['image_input'];
            $cost=39000;
        } elseif($attempt->model==='wan-video/wan-2.5-i2v') {
            $canonical=['image'=>$input['image']??null,'prompt'=>$input['prompt']??null,'duration'=>$input['duration']??null,'resolution'=>$input['resolution']??null,'enable_prompt_expansion'=>$input['enable_prompt_expansion']??null];
            abort_unless(in_array($canonical['duration'],[5,10],true) && $canonical['resolution']==='480p' && $canonical['enable_prompt_expansion']===false,409,'Animation tariff mismatch.');
            $cost=$canonical['duration']*50000;
        } else abort(409,'Unverified tariff. Hold retained.');
        $hash=hash('sha256',json_encode($canonical,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR));
        abort_unless(hash_equals($attempt->request_hash,$hash) && $cost<=$attempt->cost_limit_microusd,409,'Provider input or metered ceiling mismatch. Hold retained.');
        return new VerifiedAttemptReceipt($attempt->id,'succeeded',$attempt->prediction_id,$cost,'pilot-tariff:2026-09-29; provider-verified usage; estimate, not invoice');
    }

    /** No generations are created here. Provider status is verified with a GET.
     * Replicate does not supply an invoice cost in our verified model contract:
     * money requires explicit operator attestation against a billing reference.
     * Token estimates and worker-provided numbers are never silently called actual cost.
     */
    public function verify(object $attempt, ?int $cost, ?string $billingReference): VerifiedAttemptReceipt
    {
        // Our own offline step (a render on the worker) has no provider and no bill: interrupted, it settles as failed at
        // zero cost. Anything with a cost ceiling is not offline and needs its provider's receipt (G-REC drill, 2026-10-08).
        if ($attempt->provider === 'offline') {
            abort_unless((int) $attempt->cost_limit_microusd === 0 && (int) $attempt->credit_limit === 0, 409, 'This offline step has a cost ceiling; it needs a receipt.');
            abort_unless(($cost ?? 0) === 0, 422, 'An offline step has no provider cost; supply --cost-microusd=0.');
            return new VerifiedAttemptReceipt($attempt->id, 'failed', null, 0, 'offline step interrupted when its worker stopped; no provider billing');
        }
        abort_unless($attempt->provider === 'replicate', 422, 'No verified receipt adapter for this provider.');
        abort_unless(preg_match('/^[a-zA-Z0-9_-]{1,160}$/D',$attempt->prediction_id ?? ''), 409, 'The prediction ID was not recorded. Locate it before reconciliation.');
        abort_unless($cost !== null && $cost >= 0 && $cost <= $attempt->cost_limit_microusd
            && is_string($billingReference) && strlen(trim($billingReference)) >= 8 && strlen($billingReference) <= 500,
            422, 'An actual billing cost within the approved ceiling and its evidence reference are required.');
        $token = config('services.replicate.api_token') ?: config('services.replicate.api_key');
        abort_unless($token, 503, 'Provider verification credential is unavailable.');
        $response = Http::withToken($token)->acceptJson()->timeout(20)->withOptions(['allow_redirects'=>false])
            ->get('https://api.replicate.com/v1/predictions/'.$attempt->prediction_id);
        abort_unless($response->successful(), 503, 'Provider receipt verification failed; hold retained.');
        $p = $response->json();
        abort_unless(($p['id']??null) === $attempt->prediction_id && ($p['model']??null) === $attempt->model, 409, 'Provider receipt does not match the recorded attempt.');
        $input = $p['input'] ?? [];
        $requestHash = hash('sha256',json_encode(['prompt'=>$input['prompt']??null,'system'=>$input['system_prompt']??null,
            'maxTokens'=>$input['max_tokens']??null,'image'=>$input['image']??null], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR));
        abort_unless(hash_equals($attempt->request_hash,$requestHash),409,'Provider input does not match the approved attempt.');
        abort_unless(in_array($p['status']??'', ['succeeded','failed','canceled'],true), 409, 'Provider work is not terminal; hold retained.');
        return new VerifiedAttemptReceipt($attempt->id,$p['status']==='succeeded'?'succeeded':'failed',$attempt->prediction_id,$cost,
            'Provider terminal status verified; operator billing reference: '.trim($billingReference));
    }
}

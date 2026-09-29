<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\Http;

class ProviderReceiptVerifier
{
    /** No generations are created here. Provider status is verified with a GET.
     * Replicate does not supply an invoice cost in our verified model contract:
     * money requires explicit operator attestation against a billing reference.
     * Token estimates and worker-provided numbers are never silently called actual cost.
     */
    public function verify(object $attempt, ?int $cost, ?string $billingReference): VerifiedAttemptReceipt
    {
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

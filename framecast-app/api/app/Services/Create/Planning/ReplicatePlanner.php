<?php
namespace App\Services\Create\Planning;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** One planning call to a Claude model hosted on Replicate. */
class ReplicatePlanner implements Planner
{
    public function __construct(private string $model, private string $token) {}

    public function plan(array $context): array
    {
        $start = Http::withToken($this->token)->acceptJson()->timeout(30)
            ->post("https://api.replicate.com/v1/models/{$this->model}/predictions", ['input' => [
                'prompt' => PlanPrompt::user($context), 'system_prompt' => PlanPrompt::system(), 'max_tokens' => 3000,
            ]]);
        if (! $start->successful()) throw new RuntimeException('Planner failed to start.');
        $prediction = $start->json(); $deadline = microtime(true) + 90;
        while (in_array($prediction['status'] ?? '', ['starting', 'processing'], true)) {
            if (microtime(true) > $deadline) throw new RuntimeException('Planner timed out.');
            usleep(800000);
            $prediction = Http::withToken($this->token)->acceptJson()->timeout(15)->get('https://api.replicate.com/v1/predictions/'.$prediction['id'])->json();
        }
        if (($prediction['status'] ?? '') !== 'succeeded') throw new RuntimeException('Planner did not finish.');
        $plan = PlanPrompt::extract(implode('', (array) ($prediction['output'] ?? [])));
        if (! $plan) throw new RuntimeException('Planner returned no plan.');
        $m = $prediction['metrics'] ?? [];
        return ['plan' => $plan, 'provider' => 'replicate:'.$this->model,
            'usage' => ['prediction_id' => $prediction['id'] ?? null, 'input_tokens' => $m['input_token_count'] ?? null, 'output_tokens' => $m['output_token_count'] ?? null]];
    }
}

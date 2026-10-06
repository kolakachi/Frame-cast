<?php
namespace App\Services\Create;

use App\Models\Asset;
use Illuminate\Support\Facades\Cache;

/**
 * What planning spends besides the planner's own calls: the questions asked first, sorting the user's files, and
 * studying a reference video or reading a page. A question can come several requests before the plan, so each
 * cost is kept with the conversation until its next plan is made, and billed with it (CostEstimate::planningCharge).
 * A study or reading saved on a file is billed once, by the first plan that uses it.
 */
final class PlanningCosts
{
    private static ?string $conversation = null;

    /** Costs recorded from here on belong to this conversation's next plan. */
    public static function begin(string $conversationId): void { self::$conversation = $conversationId; }

    public static function add(string $what, int $microusd): void
    {
        if (! self::$conversation || $microusd <= 0) return;
        $key = self::key(self::$conversation);
        $have = Cache::get($key, []);
        $have[$what] = ($have[$what] ?? 0) + $microusd;
        Cache::put($key, $have, now()->addDays(2));
    }

    /** A model call's cost from its usage, at the model's rates (create.model_rates; the build tariff otherwise). */
    public static function call(string $what, string $model, array $usage): void
    {
        $r = config('create.model_rates.'.$model) ?? config('create.anthropic_rates');
        self::add($what, (int) ceil(($usage['input_tokens'] ?? 0) * $r['input'] + ($usage['output_tokens'] ?? 0) * $r['output']
            + ($usage['cache_creation_input_tokens'] ?? 0) * ($r['cache_write'] ?? $r['input']) + ($usage['cache_read_input_tokens'] ?? 0) * ($r['cache_read'] ?? 0)));
    }

    /** A cost saved on a file (a study, its sound, its layout, a page's reading), billed the first time a plan uses it. */
    public static function once(Asset $asset, string $path, string $costKey, string $what): void
    {
        $meta = (array) $asset->metadata_json;
        $part = data_get($meta, $path);
        if (! is_array($part) || ! empty($part['planning_billed']) || (int) ($part[$costKey] ?? 0) <= 0) return;
        self::add($what, (int) $part[$costKey]);
        data_set($meta, $path.'.planning_billed', true);
        $asset->forceFill(['metadata_json' => $meta])->save();
    }

    /** Everything kept for this conversation, by part, in micro-dollars; cleared. */
    public static function take(string $conversationId): array
    {
        return Cache::pull(self::key($conversationId), []);
    }

    private static function key(string $id): string { return 'create:planning-costs:'.$id; }
}

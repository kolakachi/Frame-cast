<?php

namespace App\Support;

use App\Services\CreditService;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * One way to refuse a feature the workspace's plan does not include: 402 upgrade_required, naming the feature so the
 * app can offer the right upgrade. The gates were added for the Launchpad launch (CreditService::GATE_DEFAULTS keeps
 * every other plan as it was), so in practice only launch buyers ever meet them.
 */
final class PlanGate
{
    public const FEATURES = [
        'create'          => 'The Creative Director',
        'reference_style' => 'Matching the style of a reference video',
        'series'          => 'Series',
        'batch_variations'=> 'Batch variations',
        'sample_vault'    => 'The done-for-you template vault',
    ];

    /** A flag gate: null (today's rule) and true allow, false refuses. */
    public static function allows(int $workspaceId, string $gate): bool
    {
        return app(CreditService::class)->limitFor($workspaceId, $gate) !== false;
    }

    public static function require(int $workspaceId, string $gate): void
    {
        if (! self::allows($workspaceId, $gate)) {
            self::deny($gate);
        }
    }

    public static function deny(string $gate, ?string $message = null): never
    {
        throw new HttpResponseException(response()->json(['error' => [
            'code'    => 'upgrade_required',
            'feature' => $gate,
            'message' => $message ?? (self::FEATURES[$gate] ?? 'This feature').' is not included in your plan. Upgrade to unlock it.',
        ]], 402));
    }
}

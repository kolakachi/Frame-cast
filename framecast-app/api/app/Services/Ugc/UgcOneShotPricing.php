<?php

namespace App\Services\Ugc;

use App\Models\Asset;
use App\Models\Character;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Validation\ValidationException;

/**
 * The price of a one-take UGC ad, decided the way UgcController::generateOneShot
 * decides it: which engine the take lands on (a cast face goes to Veo HQ,
 * a demo embed or the Test Pass to Seedance, a plan the single-take compiler
 * cannot fit to Veo), whether it is a 480p draft, and seconds × rate. Kept in
 * step with the controller by hand; the controller cross-checks the quoted
 * credits before generating, so drift fails closed rather than under-charging.
 */
class UgcOneShotPricing
{
    /**
     * @param  array<string, mixed>  $v  the generate-one-shot input
     * @param  list<array<string, mixed>>  $segments  normalised
     * @return array{engine: string, quote: int, plan_seconds: int, draft: bool, presenter_attached: bool}
     */
    public static function price(User $user, array $v, array $segments, CreditService $credits): array
    {
        $workspaceId = (int) $user->workspace_id;
        $engine = 'seedance25';
        $isTestPass = $credits->planTier($workspaceId) === 'ugc_pass';

        $presenter = trim((string) ($v['presenter_description'] ?? ''));
        $presenterAttached = false;
        if (! empty($v['character_id'])) {
            $c = Character::query()->whereKey($v['character_id'])
                ->where(fn ($q) => $q->where('workspace_id', $workspaceId)->orWhere(fn ($sq) => $sq->whereNull('workspace_id')->where('is_stock', true)))
                ->first();
            if ($c && ($v['cast_style'] ?? 'exact') === 'variant') {
                $presenter = $presenter ?: (string) $c->name;
            } elseif ($c) {
                $presenter = $presenter ?: trim($c->name.($c->description ? ' — '.$c->description : ''));
                $ref = $c->reference_asset_id ? Asset::query()->whereKey($c->reference_asset_id)->first() : null;
                $presenterAttached = (bool) ($ref && str_starts_with((string) $ref->mime_type, 'image/') && $ref->storage_url);
            }
        }
        if ($presenterAttached) {
            $engine = 'veo_hq';
        }
        if ($isTestPass && ! $presenterAttached) {
            $engine = 'seedance25';
        }

        $demo = false;
        if (! empty($v['demo_asset_id'])) {
            $demoAsset = Asset::query()->whereKey($v['demo_asset_id'])->where('workspace_id', $workspaceId)->where('asset_type', 'video')->first();
            if (! $demoAsset) {
                throw ValidationException::withMessages(['demo_asset_id' => 'That demo clip could not be found in this workspace.']);
            }
            if ($demoAsset->duration_seconds && (float) $demoAsset->duration_seconds > 30.0) {
                throw ValidationException::withMessages(['demo_asset_id' => 'The demo clip is longer than 30 seconds — trim it and upload a shorter cut.']);
            }
            $demo = true;
            $engine = 'seedance25';
            $presenterAttached = false;
        }

        $style = [
            'presenter' => $presenter,
            'setting' => trim((string) ($v['setting'] ?? '')),
            'product' => trim((string) ($v['product'] ?? '')),
            'tone' => trim((string) ($v['tone'] ?? '')),
            'pronunciations' => '',
            'demo' => $demo,
        ];
        $single = $engine === 'seedance25' ? UgcOneShotCompiler::compileSingle($segments, $style) : null;
        if ($demo && $single === null) {
            throw ValidationException::withMessages(['demo_asset_id' => 'A demo-embed ad must be 30 seconds or under — shorten the plan to include the demo clip.']);
        }
        if ($engine === 'seedance25' && $single === null) {
            $engine = 'veo';
        }
        $planSeconds = max(4, (int) ceil(array_sum(array_map(fn ($s) => max(1, (float) $s['seconds']), $segments))));
        $draft = $engine === 'seedance25' && ! $demo && ($v['quality'] ?? 'full') === 'draft';
        $perSecond = $draft ? CreditService::VIDEO_ONESHOT_SEEDANCE_DRAFT : CreditService::VIDEO_ONESHOT_PER_SECOND[$engine];

        return ['engine' => $engine, 'quote' => (int) ($planSeconds * $perSecond), 'plan_seconds' => $planSeconds, 'draft' => $draft, 'presenter_attached' => $presenterAttached];
    }
}

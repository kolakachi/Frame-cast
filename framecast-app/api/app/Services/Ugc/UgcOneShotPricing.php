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
    /** Reused silent footage needs no video generation; narration over footage still does. */
    public static function billableSeconds(array $segments): int
    {
        $seconds = array_sum(array_map(fn ($s) => ($s['kind'] ?? '') === 'b_roll'
            && in_array($s['source'] ?? '', ['upload', 'stock'], true) && ! empty($s['asset_id']) && trim((string) ($s['script_text'] ?? '')) === ''
                ? 0 : max(1, (float) ($s['seconds'] ?? 0)), $segments));
        return $seconds > 0 ? max(4, (int) ceil($seconds)) : 0;
    }

    /**
     * What a take is billed for: the seconds actually generated, as the compiler cut them (#4, 2026-10-12): each clip's
     * finishing second is real generation, and a 44-word line billed 17 s was made as 20 s. Clips that only replay the
     * user's own footage are not generated, so they are free. Veo snaps clips to 4/6/8 s; that overage stays ours.
     *
     * @param  list<array<string, mixed>>  $chunks  UgcOneShotCompiler output
     */
    public static function quoteSeconds(array $chunks, array $segments, string $engine): int
    {
        $generated = array_values(array_filter($chunks, fn ($c) => empty($c['source_asset_id']) || trim((string) ($c['dialogue'] ?? '')) !== ''));
        $seconds = array_sum(array_map(fn ($c) => (float) ($c['seconds'] ?? 0), $generated));
        if (in_array($engine, ['veo', 'veo_hq'], true)) $seconds = min($seconds, self::billableSeconds($segments) + count($generated));

        return $seconds > 0 ? max(4, (int) ceil($seconds)) : 0;
    }

    /**
     * @param  array<string, mixed>  $v  the generate-one-shot input
     * @param  list<array<string, mixed>>  $segments  normalised
     * @return array{engine: string, quote: int, plan_seconds: int, draft: bool, presenter_attached: bool}
     */
    public static function price(User $user, array $v, array $segments, CreditService $credits): array
    {
        $workspaceId = (int) $user->workspace_id;
        // Mirror the controller's own first move. This used to hardcode
        // seedance25, so any caller asking for another engine was quoted at
        // Seedance's rate and then refused by the credits cross-check.
        $engine = in_array($v['engine'] ?? null, ['seedance25', 'veo', 'veo_hq', 'omni'], true)
            ? (string) $v['engine']
            : 'seedance25';
        $isTestPass = $credits->planTier($workspaceId) === 'ugc_pass';

        $presenter = trim((string) ($v['presenter_description'] ?? ''));
        $presenterAttached = false;
        if (! empty($v['character_id'])) {
            $c = Character::query()->whereKey($v['character_id'])
                ->where(fn ($q) => $q->where('workspace_id', $workspaceId)->orWhere(fn ($sq) => $sq->whereNull('workspace_id')->where('is_stock', true)))
                ->first();
            if ($c && (($v['engine'] ?? null) === 'seedance25' || ($v['cast_style'] ?? 'exact') === 'variant')) {
                $presenter = $presenter ?: (string) $c->name;
            } elseif ($c) {
                $presenter = $presenter ?: trim($c->name.($c->description ? ' — '.$c->description : ''));
                $ref = $c->reference_asset_id ? Asset::query()->whereKey($c->reference_asset_id)->first() : null;
                $presenterAttached = (bool) ($ref && str_starts_with((string) $ref->mime_type, 'image/') && $ref->storage_url);
            }
        }
        if ($presenterAttached && ! in_array($engine, ['omni', 'veo', 'veo_hq'], true)) {
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
            'ugc_type' => $v['ugc_type'] ?? '',
            'presenter' => $presenter,
            'setting' => ! empty($v['setting_key']) ? UgcSettings::direction((string) $v['setting_key']) : trim((string) ($v['setting'] ?? '')),
            'place_note' => ! empty($v['setting_key']) ? UgcSettings::note((string) $v['setting_key'], true) : '',
            'product' => trim((string) ($v['product'] ?? '')),
            'tone' => trim((string) ($v['tone'] ?? '')),
            'pronunciations' => '',
            'demo' => $demo,
        ];
        if (($v['ugc_type'] ?? '') === 'skit') $engine = 'omni';
        \App\Services\Ugc\UgcComposition::resolve($user, $v['composition'] ?? []);
        $hasSources = collect($segments)->contains(fn ($s) => ($s['kind'] ?? '') === 'b_roll' && ($s['source'] ?? '') !== 'generate');
        $single = $engine === 'seedance25' && ! $hasSources ? UgcOneShotCompiler::compileSingle($segments, $style) : null;
        if ($demo && $single === null) {
            throw ValidationException::withMessages(['demo_asset_id' => 'A demo-embed ad must be 30 seconds or under — shorten the plan to include the demo clip.']);
        }
        if ($engine === 'seedance25' && $single === null) {
            $engine = 'veo';
        }
        $planSeconds = max(4, (int) ceil(array_sum(array_map(fn ($s) => max(1, (float) $s['seconds']), $segments))));
        $draft = $engine === 'seedance25' && ! $demo && ($v['quality'] ?? 'full') === 'draft';
        $perSecond = $draft ? CreditService::VIDEO_ONESHOT_SEEDANCE_DRAFT : CreditService::VIDEO_ONESHOT_PER_SECOND[$engine];

        $chunks = $single !== null ? [$single] : UgcOneShotCompiler::compile($segments, $style,
            $engine === 'omni' ? UgcOneShotCompiler::OMNI_SEGMENT_SECONDS : UgcOneShotCompiler::MAX_SEGMENT_SECONDS);

        return ['engine' => $engine, 'quote' => (int) (self::quoteSeconds($chunks, $segments, $engine) * $perSecond), 'plan_seconds' => $planSeconds, 'draft' => $draft, 'presenter_attached' => $presenterAttached];
    }
}

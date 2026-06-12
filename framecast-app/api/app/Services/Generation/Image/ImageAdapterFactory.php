<?php

namespace App\Services\Generation\Image;

/**
 * Resolves a user-selected image-model key to the right adapter instance.
 *
 * Keys are the source of truth for the UI picker AND the credit pricing —
 * keep AVAILABLE in sync with the Vue editor's IMAGE_MODEL_OPTIONS and the
 * one-shot wizard.
 *
 * Pricing LOCKED to spec/CREDIT_CALIBRATION.md (Option B): credits =
 * round(COGS ÷ $0.004) → uniform ~60% margin; retail 1cr = $0.01.
 *   gpt-image-1 medium ~$0.063 -> 16cr
 *   gpt-image-2        ~$0.17  -> 43cr
 *   gpt-image-2 (char) ~$0.20  -> 50cr (AI_CHARACTER, /edits path)
 *   nano-banana        ~$0.039 -> 10cr
 *   flux-schnell       ~$0.003 ->  1cr  (price-leader, 1cr floor)
 *   sdxl-lightning     ~$0.003 ->  1cr  (same tier as flux)
 */
class ImageAdapterFactory
{
    /**
     * UI-facing registry. Each entry tells both the picker (label/cost) and
     * the dispatcher (which adapter to resolve). Read by /api/v1/image-models
     * so the frontend stays in sync without a parallel list to maintain.
     */
    public const AVAILABLE = [
        'gpt-image-1' => [
            'label'   => 'GPT Image 1',
            'sub'     => 'OpenAI · photoreal default',
            'cost'    => 16,
            'render'  => '~20s',
            'adapter' => DalleImageAdapter::class,
        ],
        'gpt-image-2' => [
            'label'   => 'GPT Image 2',
            'sub'     => 'OpenAI · newer, higher fidelity',
            'cost'    => 43,
            'render'  => '~30s',
            // Routes through DalleImageAdapter (text-to-image /generations)
            // with the model overridden to gpt-image-2 via openai_model.
            // The character /edits path is auto-routed separately by
            // GenerateAIImageJob when a character with reference asset is
            // bound to the scene — that path uses CharacterImageAdapter
            // regardless of which model the user picked here.
            'adapter'       => DalleImageAdapter::class,
            'openai_model'  => 'gpt-image-2',
            'requires_reference' => false,
        ],
        'nano-banana' => [
            'label'   => 'Nano Banana',
            'sub'     => 'Google · cheap fast portraits',
            // ~$0.039 COGS → round(÷$0.004) = 10cr (uniform 60% margin).
            'cost'    => 10,
            'render'  => '~10s',
            'adapter' => NanoBananaImageAdapter::class,
        ],
        'nano-banana-pro' => [
            'label'   => 'Nano Banana Pro',
            'sub'     => 'Google · best identity + reference fidelity',
            // ~$0.134 COGS → round(÷$0.004) ≈ 35cr.
            'cost'    => 35,
            'render'  => '~30s',
            'adapter' => NanoBananaProImageAdapter::class,
        ],
        'flux-schnell' => [
            'label'   => 'Flux Schnell',
            'sub'     => 'BFL · cheapest, ~3s render',
            // ~$0.003 COGS rounds below 1cr → held at the 1cr floor.
            'cost'    => 1,
            'render'  => '~5s',
            'adapter' => FluxSchnellImageAdapter::class,
        ],
        'sdxl-lightning' => [
            'label'   => 'SDXL Lightning',
            'sub'     => 'ByteDance · cheap stylish',
            'cost'    => 1,    // same tier as flux-schnell (1cr floor)
            'render'  => '~5s',
            'adapter' => SdxlLightningImageAdapter::class,
        ],
    ];

    /**
     * Resolve a model key to an instantiated adapter. Falls back to
     * gpt-image-1 (the default photoreal path) when the key is unknown
     * or null — keeps existing callers that don't pass modelKey working.
     */
    public function resolve(?string $modelKey): ImageGenerationAdapter
    {
        $key = $modelKey && isset(self::AVAILABLE[$modelKey])
            ? $modelKey
            : 'gpt-image-1';
        return app(self::AVAILABLE[$key]['adapter']);
    }

    /**
     * Credit cost for a model key. Mirrors AVAILABLE; centralized here so
     * the controller's pre-flight balance check stays in lockstep with what
     * the job actually deducts.
     */
    public function costFor(?string $modelKey): int
    {
        $key = $modelKey && isset(self::AVAILABLE[$modelKey])
            ? $modelKey
            : 'gpt-image-1';
        return (int) (self::AVAILABLE[$key]['cost'] ?? 16);
    }

    /**
     * The credit cost to CHARGE for an image generation — the single source of
     * truth shared by the controller pre-flight check and the job's deduction,
     * so quote always equals charge (CREDIT_CALIBRATION.md §3).
     *
     * Precedence:
     *   1. Character/reference path (gpt-image-2 /edits) → AI_CHARACTER.
     *   2. An explicit non-default model pick → that model's per-model cost.
     *   3. Default gpt-image-1 (or null) → honor the legacy high-quality flag
     *      (AI_HIGH) else AI_MEDIUM.
     */
    public function generationCost(?string $modelKey, bool $expectsCharacter = false, ?string $aiQuality = null): int
    {
        if ($expectsCharacter) {
            return \App\Services\CreditService::AI_CHARACTER;
        }
        if ($modelKey && $modelKey !== 'gpt-image-1' && isset(self::AVAILABLE[$modelKey])) {
            return $this->costFor($modelKey);
        }
        return $aiQuality === 'high'
            ? \App\Services\CreditService::AI_HIGH
            : \App\Services\CreditService::AI_MEDIUM;
    }

    /**
     * Whether a reference/character generation should run on gpt-image-2. We
     * default reference work to nano-banana-pro (better identity/skin-tone
     * fidelity); gpt-image-2 only when the user explicitly picks it.
     */
    public function referenceUsesGptImage2(?string $modelKey): bool
    {
        return $modelKey === 'gpt-image-2';
    }

    /**
     * Adapter for a reference/character generation, given the picked model.
     * nano-banana-pro is the default (best identity); explicit picks honoured.
     * Shared by GenerateAIImageJob + GenerateCharacterImageJob.
     */
    public function referenceAdapter(?string $modelKey): object
    {
        return match ($modelKey) {
            'gpt-image-2' => app(CharacterImageAdapter::class),
            'nano-banana' => app(NanoBananaImageAdapter::class),
            default       => app(NanoBananaProImageAdapter::class),
        };
    }

    /**
     * Credit cost for a reference generation, given the picked model. The
     * default (no/unsupported pick) is nano-banana-pro — best identity
     * fidelity. Explicit picks (nano-banana, gpt-image-2) are honoured.
     */
    public function referenceGenerationCost(?string $modelKey): int
    {
        return match ($modelKey) {
            'gpt-image-2'     => \App\Services\CreditService::AI_CHARACTER, // gpt-image-2 /edits
            'nano-banana'     => $this->costFor('nano-banana'),
            default           => $this->costFor('nano-banana-pro'),        // default
        };
    }

    /** COGS key for a reference generation, given the picked model. */
    public function referenceCogsKey(?string $modelKey): string
    {
        return match ($modelKey) {
            'gpt-image-2'     => 'ai_image:character',
            'nano-banana'     => 'ai_image:nano-banana',
            default           => 'ai_image:nano-banana-pro',
        };
    }

    /** COGS key for the ledger's upstream_cost_usd (see CreditService::COGS_USD). */
    public function cogsKey(?string $modelKey, bool $ranCharacter = false): string
    {
        if ($ranCharacter) {
            return 'ai_image:character';
        }
        $key = $modelKey && isset(self::AVAILABLE[$modelKey]) ? $modelKey : 'gpt-image-1';
        return 'ai_image:'.$key;
    }

    /**
     * For OpenAI-backed entries, return the specific model name to pass to
     * the /v1/images/generations call. Lets the picker pin a model name
     * (e.g. gpt-image-2) without touching the global config default.
     * Null for non-OpenAI models — the adapter ignores it.
     */
    public function openaiModelOverride(?string $modelKey): ?string
    {
        $key = $modelKey && isset(self::AVAILABLE[$modelKey])
            ? $modelKey
            : null;
        return $key ? (self::AVAILABLE[$key]['openai_model'] ?? null) : null;
    }

    /**
     * Public-API view of AVAILABLE — strips the adapter class names so we
     * don't leak server internals to the frontend.
     */
    public static function publicCatalog(): array
    {
        return array_map(
            fn (array $cfg, string $key) => [
                'key'    => $key,
                'label'  => $cfg['label'],
                'sub'    => $cfg['sub'],
                'cost'   => $cfg['cost'],
                'render' => $cfg['render'],
                'requires_reference' => (bool) ($cfg['requires_reference'] ?? false),
            ],
            self::AVAILABLE,
            array_keys(self::AVAILABLE),
        );
    }
}

<?php

namespace App\Services\Generation\Image;

/**
 * Resolves a user-selected image-model key to the right adapter instance.
 *
 * Keys are the source of truth for the UI picker AND the credit pricing —
 * keep AVAILABLE in sync with the Vue editor's IMAGE_MODEL_OPTIONS and the
 * one-shot wizard.
 *
 * Pricing is approximate retail at 1cr = $0.005:
 *   gpt-image-1        ~$0.17 -> 35cr (mapped to AI_MEDIUM=15 for now)
 *   gpt-image-2 (char) ~$0.30 -> 60cr (mapped to AI_CHARACTER=50)
 *   nano-banana        ~$0.04 ->  8cr
 *   flux-schnell       ~$0.003 -> 1cr  (price-leader option)
 *   sdxl-lightning     ~$0.003 -> 1cr  (same tier as flux)
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
            'cost'    => 15,
            'render'  => '~20s',
            'adapter' => DalleImageAdapter::class,
        ],
        'gpt-image-2' => [
            'label'   => 'GPT Image 2',
            'sub'     => 'OpenAI · newer, higher fidelity',
            'cost'    => 35,
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
            'cost'    => 8,
            'render'  => '~10s',
            'adapter' => NanoBananaImageAdapter::class,
        ],
        'flux-schnell' => [
            'label'   => 'Flux Schnell',
            'sub'     => 'BFL · cheapest, ~3s render',
            'cost'    => 1,
            'render'  => '~5s',
            'adapter' => FluxSchnellImageAdapter::class,
        ],
        'sdxl-lightning' => [
            'label'   => 'SDXL Lightning',
            'sub'     => 'ByteDance · cheap stylish',
            'cost'    => 1,
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
        return (int) (self::AVAILABLE[$key]['cost'] ?? 15);
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

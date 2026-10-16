<?php

namespace App\Services\Create;

use App\Models\{Asset, User};

/**
 * Our own videos on Create's empty screen (C2, 2026-10-09; owner picked "box first, one strip below"). "Make one like
 * this" attaches the sample as the style reference, so the plan keeps its style and the user brings the product, the
 * words and the brand. A sample is copied into the workspace once and reused after that.
 */
class SampleLibrary
{
    /** Every example on the landing page and B2 (32): id => [folder, title, filter kind, seconds, shape]. "All" mixes kinds. */
    public const SAMPLES = [
        'b01' => ['weave', 'Launch promo in bold kinetic type', 'launch', 20, 'portrait'],
        's100' => ['weave', '$100 a month for 30 years, as a data story', 'explainer', 20, 'portrait'],
        'b05' => ['weave', 'Creator testimonial', 'ugc', 20, 'portrait'],
        'l30703d4a' => ['weave', 'Northside Roasters coffee subscription', 'ads', 15, 'portrait'],
        'l3461c7aa' => ['weave', 'Illustrated story, drawing style kept', 'explainer', 15, 'portrait'],
        'l0f70e0d0' => ['weave', 'App demo, told as a POV', 'launch', 15, 'portrait'],
        'le0c668f6' => ['weave', 'For small shop owners, with 3D icons', 'ads', 15, 'portrait'],
        'b10' => ['weave', '3 hooks that stop the scroll', 'explainer', 30, 'portrait'],
        'b06' => ['weave', 'Unboxing with close-ups', 'ugc', 20, 'portrait'],
        'l540' => ['weave', 'Kinetic launch teaser', 'launch', 15, 'portrait'],
        'b03' => ['weave', 'Dewbloom serum product ad', 'ads', 15, 'portrait'],
        'l21287e12' => ['weave', 'Flat-colour explainer with a chart', 'explainer', 30, 'portrait'],
        'b07' => ['weave', 'Split-screen reaction ad', 'ugc', 15, 'portrait'],
        'lf23a856a' => ['weave', 'Dark product promo', 'ads', 30, 'portrait'],
        'lc809947b' => ['weave', 'Creator explainer', 'explainer', 15, 'portrait'],
        'l36b53c6d' => ['weave', 'Talking-head creator ad', 'ugc', 30, 'portrait'],
        'led2' => ['weave', 'Three steps to a product ad', 'ads', 15, 'portrait'],
        'l292' => ['weave', 'Bold type with a shape reveal', 'launch', 15, 'portrait'],
        'ugc-1' => ['ugc', 'Direct-to-camera ad', 'ugc', 15, 'portrait'],
        'l8623176a' => ['weave', 'App stat promo: invoices paid 2x faster', 'ads', 20, 'square'],
        'le55486c6' => ['weave', 'Ember & Oak candle offer', 'ads', 15, 'portrait'],
        'lbaf5605f' => ['weave', '"Describe it." kinetic promo', 'launch', 15, 'portrait'],
        'l60f51590' => ['weave', 'Day-in-the-life, same format', 'ugc', 10, 'portrait'],
        'stock-presenter' => ['ugc', 'Presenter we cast for you', 'ugc', 5, 'portrait'],
        'l1c4' => ['weave', 'One-line brief to video', 'launch', 15, 'portrait'],
        'any-scene' => ['ugc', 'Your character, any scene', 'ugc', 5, 'portrait'],
        'l1317b615' => ['weave', 'Kinetic type, word by word', 'launch', 15, 'portrait'],
        'lf7bd88af' => ['weave', 'Your character in a new scene', 'ugc', 5, 'portrait'],
        'b08' => ['weave', 'Screen recording to a 30s promo', 'footage', 30, 'portrait'],
        'b09' => ['weave', '2-minute video to a 30s cut', 'footage', 30, 'landscape'],
        'b02' => ['weave', 'Product walkthrough, step by step', 'explainer', 30, 'landscape'],
        'app-demo' => ['ugc', 'Embed a real app or product demo', 'ugc', 10, 'landscape'],
    ];

    /** The strip's old filter kinds, for templates added after the examples (their tabs on the Templates page differ). */
    private const KIND = ['offer' => 'ads', 'app' => 'launch', 'announce' => 'launch', 'explainer' => 'explainer', 'ugc' => 'ugc', 'footage' => 'footage'];

    /**
     * The examples on the empty screen, now read from the template library (Templates, 2026-10-16: one library). Light
     * enough for the screen: a 260 px WebP poster and a 6-second 360p silent preview for hover, cached for a year; the
     * full video is fetched only when "Make one like this" uses it.
     *
     * @return list<array{id: string, title: string, kind: string, seconds: int, shape: string, poster_url: ?string, preview_url: ?string, video_url: ?string}>
     */
    public static function catalogueFor(User $user): array
    {
        return array_values(array_map(fn ($t) => ['id' => $t['id'], 'title' => $t['title'], 'kind' => self::SAMPLES[$t['id']][2] ?? (self::KIND[$t['category']] ?? 'ads'),
            'seconds' => $t['seconds'], 'shape' => ['9:16' => 'portrait', '1:1' => 'square', '16:9' => 'landscape'][$t['shape']] ?? 'portrait',
            'poster_url' => $t['poster_url'], 'preview_url' => $t['preview_url'], 'video_url' => $t['video_url']],
            array_filter(app(TemplateLibrary::class)->visible($user), fn ($t) => ! $t['locked'] && ($t['on_samples'] || config('create.templates_public')))));
    }

    /** "Make one like this": the example as the conversation's reference, copied into the workspace once and studied. */
    public function attach(User $user, string $conversationId, string $sampleId, int $version): Asset
    {
        $visible = collect(app(TemplateLibrary::class)->visible($user))->firstWhere('id', $sampleId);
        abort_unless($visible, 422, 'That example is no longer available.');
        if ($visible['locked']) \App\Support\PlanGate::deny('sample_vault');
        app(ConversationService::class)->authorize($user, true);
        return app(TemplateLibrary::class)->reference($user, $conversationId, $sampleId, $version);
    }
}

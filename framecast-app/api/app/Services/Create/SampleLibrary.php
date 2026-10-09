<?php

namespace App\Services\Create;

use App\Models\{Asset, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;

/**
 * Our own videos on Create's empty screen (C2, 2026-10-09; owner picked "box first, one strip below"). "Make one like
 * this" attaches the sample as the style reference, so the plan keeps its style and the user brings the product, the
 * words and the brand. A sample is copied into the workspace once and reused after that.
 */
class SampleLibrary
{
    private const BASE = 'https://s3.us-east-005.backblazeb2.com/frame-cast/marketing/samples/';

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

    /**
     * Light enough for the empty screen: a 260 px WebP poster (about 7 KB) and a 6-second 360p silent preview for hover
     * (about 80 KB), both cached for a year. The full video is fetched only when "Make one like this" uses it.
     *
     * @return list<array{id: string, title: string, kind: string, seconds: int, shape: string, poster_url: string, preview_url: string, video_url: string}>
     */
    public static function catalogue(): array
    {
        return array_map(fn ($id, $s) => ['id' => $id, 'title' => $s[1], 'kind' => $s[2], 'seconds' => $s[3], 'shape' => $s[4],
            'poster_url' => self::BASE.'thumbs/'.$id.'.webp', 'preview_url' => self::BASE.'previews/'.$id.'.mp4', 'video_url' => self::BASE.$s[0].'/'.$id.'.mp4'],
            array_keys(self::SAMPLES), self::SAMPLES);
    }

    /** Attaches the sample as the conversation's style reference: a copy made once per workspace, studied like any reference. */
    public function attach(User $user, string $conversationId, string $sampleId, int $version): Asset
    {
        abort_unless(isset(self::SAMPLES[$sampleId]), 422, 'That example is no longer available.');
        $conversations = app(ConversationService::class);
        $conversations->authorize($user, true);
        $kept = Asset::where('workspace_id', $user->workspace_id)->where('status', '!=', 'archived')->where('metadata_json->create_sample_id', $sampleId)->first();
        if ($kept) {
            $conversations->attach($user, $conversationId, (int) $kept->id, 'reference', $version);
            return $kept;
        }
        $dir = sys_get_temp_dir().'/create-sample-'.Str::uuid();
        mkdir($dir, 0700);
        try {
            $file = $dir.'/'.$sampleId.'.mp4';
            $r = Http::timeout(120)->sink($file)->get(self::BASE.self::SAMPLES[$sampleId][0].'/'.$sampleId.'.mp4');
            abort_unless($r->successful() && is_file($file) && filesize($file) > 0, 503, 'That example could not be fetched just now. Try again, or describe your video instead.');
            $asset = app(AttachmentUploadService::class)->upload($user, $conversationId, new UploadedFile($file, $sampleId.'.mp4', 'video/mp4', null, true), 'reference', 'sample:'.$conversationId.':'.$sampleId, $version);
            $asset->forceFill(['title' => 'Example: '.self::SAMPLES[$sampleId][1], 'metadata_json' => array_merge($asset->metadata_json ?? [], ['create_sample_id' => $sampleId])])->save();
            // Studied in the background like a reference attached in the app, so the plan reads a finished study.
            if (config('create.mode') !== 'fixture') {
                $effort = json_decode((string) DB::table('create_conversations')->where('id', $conversationId)->value('settings_json'), true)['reference_effort'] ?? null;
                \App\Jobs\StudyCreateReference::dispatch($asset->id, References\ReferenceStudy::coverageMode($effort))->afterCommit();
            }
            return $asset->fresh();
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }
}

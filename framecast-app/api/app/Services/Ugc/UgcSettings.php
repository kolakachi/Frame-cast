<?php

namespace App\Services\Ugc;

use App\Models\Asset;
use App\Services\Media\StorageService;

/**
 * The kinds of place a UGC ad is filmed in (config/ugc_settings.php). A setting is offered only once its background
 * photo exists; the photo reaches the video model as a loose reference for the kind of place, never a copy.
 */
class UgcSettings
{
    public static function title(string $key): string
    {
        return 'UGC setting: '.$key;
    }

    /** @return array<string, array{group: string, place: string, plate: string, wardrobe?: list<string>, rule?: string}> */
    public static function all(): array
    {
        return (array) config('ugc_settings', []);
    }

    /**
     * The company's own setting photos: workspace, tag and type all match, never a title alone (review, 2026-10-11:
     * any user could upload an image titled "UGC setting: kitchen" and have it shown and sent for every workspace).
     */
    private static function photos(): \Illuminate\Database\Eloquent\Builder
    {
        return Asset::query()->where('workspace_id', (int) config('services.ugc.settings_workspace_id', 1))
            ->where('asset_type', 'image')->where('status', 'active')->whereJsonContains('tags', 'ugc_setting')
            ->whereIn('title', array_map([self::class, 'title'], array_keys(self::all())));
    }

    /** The settings whose background photo exists, by key. */
    public static function available(): array
    {
        $titles = self::photos()->pluck('title')->all();
        return array_intersect_key(self::all(), array_flip(array_map(fn ($t) => substr($t, strlen('UGC setting: ')), $titles)));
    }

    /** The settings on offer for the picker: key, how it reads, its group and a signed link to its photo. */
    public static function listed(): array
    {
        $assets = self::photos()->orderBy('id')->get()->keyBy('title');
        $out = [];
        foreach (self::all() as $key => $s) {
            $asset = $assets->get(self::title($key));
            if (! $asset) continue;
            $out[] = ['key' => $key, 'label' => self::label($key), 'place' => $s['place'], 'group' => $s['group'],
                'image_url' => \Illuminate\Support\Facades\URL::temporarySignedRoute('media.assets.content',
                    now()->addMinutes((int) config('media.signed_url_ttl_minutes', 720)), ['assetId' => $asset->getKey()])];
        }
        return $out;
    }

    /** A short name for a chip: "Fitting room", "Car". */
    public static function label(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    /** The background photo as a data URI, or null when it is missing or unreadable. */
    public static function plate(string $key): ?string
    {
        $asset = self::photos()->where('title', self::title($key))->latest('id')->first();
        if (! $asset) return null;
        // A storage hiccup never fails a take: it is made without the place reference.
        try {
            $stream = app(StorageService::class)->readStream((string) $asset->storage_url);
            if (! is_resource($stream)) return null;
            $bytes = stream_get_contents($stream, 8 * 1024 * 1024);
            fclose($stream);
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
        return $bytes ? 'data:image/png;base64,'.base64_encode($bytes) : null;
    }

    /** Where it is filmed, for the prompt's opening sentence: "this kind of place: a beach". */
    public static function direction(string $key): string
    {
        $s = self::all()[$key] ?? null;
        return $s ? 'this kind of place: '.$s['place'] : '';
    }

    /**
     * What the prompt adds about the place: that a reference photo, when one went with the take, shows only the kind
     * of place, and any staging the place needs (a parked car, no swimwear).
     */
    public static function note(string $key, bool $photo): string
    {
        $s = self::all()[$key] ?? null;
        if (! $s) return '';
        return trim(($photo ? 'If a reference image shows a place, it shows only the kind of place: keep it '.$s['place']
            .', but choose your own spot, angle, time of day and details; never copy that image or use it as a flat backdrop. ' : '')
            .($s['rule'] ?? ''));
    }
}

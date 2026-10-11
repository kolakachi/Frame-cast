<?php

namespace App\Services\Ugc;

use App\Models\Asset;
use App\Services\Media\StorageService;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * What a product photo shows, read once (VISION, owner 2026-10-11). The UGC director only had file names, and in 3 of
 * 6 test videos a squeeze tube became a pump bottle. A cheap vision pass writes a short sheet (what it is, its form,
 * size, colours, cap, label text), kept on the asset so the same photo is never read twice. The sheet goes wherever
 * the product is described: the director's materials, the plan's brief and every clip prompt.
 */
class ProductVision
{
    /** Bumped when the sheet's fields change, so older sheets are read again. */
    private const VERSION = 2;

    private const PROMPT = 'This photo was given as a product photo for a video ad. Describe the product exactly as photographed, so a video model can show the same object. '
        .'Answer JSON only: {"kind": "product" (a clear shot of the product itself) | "lifestyle" (the product in use or in a scene, people or props around it) | "other" (a banner, logo, chart, text or no product), '
        .'"what": "what it is, in a few words (e.g. a face sunscreen)", '
        .'"form": "the physical container or object (e.g. squeeze tube, pump bottle, dropper bottle, jar, box, pouch, can, bar, garment, device)", '
        .'"size": "its rough real size, compared with a hand (e.g. about the length of a hand, palm-sized)", '
        .'"look": "the object\'s own colours, finish, cap or lid, and any shape detail that tells it apart, in one short phrase; never the photo\'s lighting, shadows or background", '
        .'"label": "the brand and product words printed on it, exactly as written; empty when none are readable"}';

    /**
     * The sheet for each image asset, in the order given; read now when not saved yet (several at once). Assets that
     * are not images of this workspace, or could not be read, are left out.
     *
     * $expected: the product a page sells, when the photos came from it; each sheet then says whether the photo shows
     * that very product ("match"), not a sibling product from the same shop.
     *
     * @param  list<int>  $assetIds
     * @return array<int, array{kind: string, what: string, form: string, size: string, look: string, label: string, match?: bool}>
     */
    public function sheets(array $assetIds, int $workspaceId, string $expected = ''): array
    {
        $assets = Asset::query()->where('workspace_id', $workspaceId)->where('asset_type', 'image')
            ->whereIn('id', array_values(array_unique(array_map('intval', $assetIds))))->get()->keyBy('id');
        $out = [];
        $todo = [];
        foreach ($assets as $id => $asset) {
            $saved = ($asset->metadata_json ?? [])['product_sheet'] ?? null;
            if (is_array($saved) && ($saved['v'] ?? 0) === self::VERSION) $out[$id] = $saved;
            else $todo[$id] = $asset;
        }
        $key = (string) config('services.anthropic.key');
        if ($todo && $key !== '') {
            // The read is charged to the workspace whose photo it is, wherever it runs.
            if (empty(\App\Services\Usage\UsageContext::current()['workspace_id'])) \App\Services\Usage\UsageContext::set($workspaceId);
            $images = [];
            foreach ($todo as $id => $asset) if (($jpeg = $this->small($asset)) !== null) $images[$id] = $jpeg;
            $responses = $images ? Http::pool(fn (Pool $pool) => array_map(fn ($id) => $pool->as((string) $id)
                ->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(45)
                ->post('https://api.anthropic.com/v1/messages', ['model' => (string) config('create.check_model', 'claude-haiku-4-5-20251001'), 'max_tokens' => 300,
                    'messages' => [['role' => 'user', 'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($images[$id])]],
                        ['type' => 'text', 'text' => self::PROMPT.($expected !== '' ? ' Also add "match": true when this photo shows this exact product, false when it shows a different product (another item from the same brand, a bundle or a set): '.mb_substr($expected, 0, 200) : '')]]]]]), array_keys($images))) : [];
            foreach ($responses as $id => $r) {
                if (! $r instanceof \Illuminate\Http\Client\Response || ! $r->successful()) {
                    Log::warning('product vision: the call failed', ['asset_id' => $id, 'status' => $r instanceof \Illuminate\Http\Client\Response ? $r->status() : null]);
                    continue;
                }
                $sheet = self::parse(collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''));
                if ($sheet === null) continue;
                $asset = $todo[(int) $id];
                $asset->metadata_json = array_merge($asset->metadata_json ?? [], ['product_sheet' => $sheet + ['v' => self::VERSION]]);
                $asset->save();
                $out[(int) $id] = $sheet + ['v' => self::VERSION];
            }
        }
        $ordered = [];
        foreach ($assetIds as $id) if (isset($out[(int) $id])) $ordered[(int) $id] = $out[(int) $id];

        return $ordered;
    }

    /**
     * The product in one phrase, from the first clear product shot (else the first photo read): "a white squeeze tube
     * with a green flip cap, about the length of a hand; label reads 'Dewbloom Daily SPF 30'". Empty when none was read.
     */
    public function look(array $assetIds, int $workspaceId): string
    {
        $sheets = $this->sheets($assetIds, $workspaceId);
        $best = collect($sheets)->first(fn ($s) => $s['kind'] === 'product') ?? collect($sheets)->first(fn ($s) => $s['kind'] === 'lifestyle');

        return $best ? self::line($best) : '';
    }

    /** One sheet as a phrase. */
    public static function line(array $s): string
    {
        $what = trim((string) ($s['what'] ?? ''));
        $form = trim((string) ($s['form'] ?? ''));
        $label = trim((string) ($s['label'] ?? ''));
        $parts = array_filter([
            $what !== '' && $form !== '' ? $what.' ('.$form.')' : ($what ?: $form),
            trim((string) ($s['look'] ?? '')),
            trim((string) ($s['size'] ?? '')),
            $label !== '' ? 'the label is printed with "'.$label.'"' : '',
        ]);

        return mb_substr(implode('; ', $parts), 0, 400);
    }

    /** The model's answer as a sheet; null when it is not one. */
    public static function parse(string $text): ?array
    {
        $a = strpos($text, '{'); $b = strrpos($text, '}');
        $json = $a !== false && $b !== false ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
        if (! is_array($json)) return null;
        $field = fn (string $k, int $max) => mb_substr(trim(is_string($json[$k] ?? null) ? $json[$k] : ''), 0, $max);
        $sheet = ['kind' => in_array($json['kind'] ?? null, ['product', 'lifestyle', 'other'], true) ? $json['kind'] : 'other',
            'what' => $field('what', 80), 'form' => $field('form', 60), 'size' => $field('size', 80), 'look' => $field('look', 160), 'label' => $field('label', 120)];

        // "Not readable" is no label; said as one, it would be written into every clip prompt.
        if (preg_match('/\b(not|un)\s*(clearly\s*)?(readable|legible|visible)|illegible|^(none|n\/a|unknown)$/i', $sheet['label'])) $sheet['label'] = '';
        if (is_bool($json['match'] ?? null)) $sheet['match'] = $json['match'];

        return $sheet['what'] === '' && $sheet['form'] === '' ? null : $sheet;
    }

    /** The photo at most 768 px wide as a JPEG (vision pricing grows with pixels); null when it cannot be read. */
    private function small(Asset $asset): ?string
    {
        $bytes = $asset->storage_url ? app(StorageService::class)->get((string) $asset->storage_url) : null;
        if (! is_string($bytes) || $bytes === '') return null;
        $base = tempnam(sys_get_temp_dir(), 'pvision-');
        $out = $base.'.jpg';
        try {
            file_put_contents($base, $bytes);
            Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-i', $base, '-frames:v', '1', '-vf', "scale='min(768,iw)':-2", '-q:v', '4', $out]);
            $jpeg = is_file($out) ? (string) file_get_contents($out) : '';

            return $jpeg !== '' ? $jpeg : null;
        } finally {
            @unlink($out);
            @unlink($base);
        }
    }
}

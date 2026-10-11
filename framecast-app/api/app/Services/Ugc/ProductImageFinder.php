<?php

namespace App\Services\Ugc;

use App\Models\{Asset, User};
use App\Services\Create\References\PageReferenceService;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\{Http, Log, Process};
use Illuminate\Support\Str;

/**
 * A pasted product link brings its product photos (VISION, owner 2026-10-11): the images the page itself names for
 * sharing and for shops (og:image, the JSON-LD Product image, a Shopify product's own feed), saved as this
 * workspace's images. Only clear product shots are kept, as read by ProductVision; banners and lifestyle shots are
 * dropped. Every address fetched, the page's and each image's, must be public https.
 */
class ProductImageFinder
{
    public const MAX = 3;
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const AGENT = 'Mozilla/5.0 (compatible; WyvStudio/1.0; +https://wyvstudio.com)';

    public function __construct(private ProductVision $vision) {}

    /**
     * Up to MAX product photos from the page, saved as image assets; their ids, best first. Empty when none were
     * found or the page could not be read: the link's text facts still stand. $product: what the page sells, as read
     * from its text, so a sibling product's photo on the same page is left out.
     *
     * @return list<int>
     */
    public function import(User $user, string $url, string $product = ''): array
    {
        try {
            $page = PageReferenceService::publicUrl($url);
            $html = $this->fetch($page, 2 * 1024 * 1024, 'text/html');
            if ($html === null) return [];
            $candidates = self::candidates($html, $page);
            if (preg_match('~^(https://[^/]+/(?:[a-z-]+/)?products/[^/?#]+)~i', $page, $m)) {
                $feed = $this->fetch($m[1].'.json', 2 * 1024 * 1024, 'application/json');
                $images = $feed !== null ? (json_decode($feed, true)['product']['images'] ?? []) : [];
                $candidates = array_values(array_unique(array_merge($candidates, array_filter(array_map(fn ($i) => is_array($i) && is_string($i['src'] ?? null) ? $i['src'] : null, (array) $images)))));
            }
        } catch (\Throwable $e) {
            Log::info('product images: page not read', ['url' => $url, 'error' => mb_substr($e->getMessage(), 0, 160)]);
            return [];
        }

        // Fetched side by side: one after another took 16 s on a real shop page.
        $candidates = array_slice(self::distinct($candidates), 0, 6);
        $bodies = $this->download($candidates);
        $saved = [];
        foreach ($candidates as $i => $src) {
            $id = isset($bodies[$i]) ? $this->save($user, $bodies[$i], $src, $page) : null;
            if ($id !== null) $saved[] = $id;
        }
        if (! $saved) return [];
        $sheets = $this->vision->sheets($saved, (int) $user->workspace_id, $product);
        $keep = array_slice(array_keys(array_filter($sheets, fn ($s) => $s['kind'] === 'product' && ($s['match'] ?? true))), 0, self::MAX);
        // What is not a product shot is not kept in their library either.
        $drop = array_diff($saved, $keep);
        if ($drop) Asset::query()->whereIn('id', $drop)->where('workspace_id', $user->workspace_id)->get()->each(function (Asset $a) {
            try { app(StorageService::class)->delete((string) $a->storage_url); } catch (\Throwable) {}
            $a->delete();
        });

        return array_values($keep);
    }

    /**
     * Image addresses the page names for its product, best first: JSON-LD Product images, then og:image and
     * twitter:image. Relative and protocol-relative addresses are made absolute against the page.
     *
     * @return list<string>
     */
    public static function candidates(string $html, string $pageUrl): array
    {
        $found = [];
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks)) {
            foreach ($blocks[1] as $block) {
                $data = json_decode(html_entity_decode(trim($block)), true);
                foreach (self::products($data) as $product) {
                    foreach ((array) ($product['image'] ?? []) as $img) {
                        $found[] = is_array($img) ? ($img['url'] ?? $img['contentUrl'] ?? null) : $img;
                    }
                }
            }
        }
        foreach (['og:image:secure_url', 'og:image', 'twitter:image'] as $name) {
            if (preg_match_all('#<meta[^>]+(?:property|name)=["\']'.preg_quote($name, '#').'["\'][^>]*>#i', $html, $tags)) {
                foreach ($tags[0] as $tag) if (preg_match('#content=["\']([^"\']+)["\']#i', $tag, $c)) $found[] = html_entity_decode($c[1]);
            }
        }
        $out = [];
        foreach ($found as $src) {
            if (! is_string($src) || ($src = trim($src)) === '') continue;
            if (str_starts_with($src, '//')) $src = 'https:'.$src;
            elseif (str_starts_with($src, '/')) $src = 'https://'.parse_url($pageUrl, PHP_URL_HOST).$src;
            if (! str_starts_with($src, 'https://')) continue;
            $out[] = $src;
        }

        return array_values(array_unique($out));
    }

    /**
     * One address per photo: shops list the same photo at several sizes (front_450x450.jpg, front_1024x1024.jpg?v=3),
     * sometimes on two hosts.
     * The largest of each is kept (no size in the name counts as full size), in the order first seen.
     *
     * @return list<string>
     */
    public static function distinct(array $urls): array
    {
        $best = [];
        foreach ($urls as $u) {
            $path = (string) parse_url($u, PHP_URL_PATH);
            $size = preg_match('/_(\d*)x(\d*)(?=\.[a-z0-9]+$)/i', $path, $m) ? max((int) $m[1], (int) $m[2]) : PHP_INT_MAX;
            // By file name alone: the page and the shop's feed serve one photo from different hosts.
            $key = strtolower(preg_replace('/_(\d+x\d*|\d*x\d+|small|medium|large|grande|master)(?=\.[a-z0-9]+$)/i', '', basename($path)));
            if (! isset($best[$key]) || $size > $best[$key][1]) $best[$key] = [$u, $size];
        }

        return array_values(array_map(fn ($b) => $b[0], $best));
    }

    /**
     * Each photo's bytes, by index, fetched side by side; a redirect is followed on its own, checked like the first.
     *
     * @return array<int, string>
     */
    private function download(array $urls): array
    {
        $ok = [];
        foreach ($urls as $i => $u) {
            try { $ok[$i] = PageReferenceService::publicUrl($u); } catch (\Throwable) {}
        }
        if (! $ok) return [];
        $responses = Http::pool(fn (\Illuminate\Http\Client\Pool $pool) => array_map(fn ($i) => $pool->as((string) $i)->connectTimeout(8)->timeout(20)
            ->withOptions(['allow_redirects' => false])->withHeaders(['User-Agent' => self::AGENT, 'Accept' => 'image/*'])->get($ok[$i]), array_keys($ok)));
        $out = [];
        foreach ($responses as $i => $r) {
            if (! $r instanceof \Illuminate\Http\Client\Response) continue;
            if ($r->redirect()) {
                try { $body = $this->fetch($ok[(int) $i], self::MAX_BYTES, 'image/'); } catch (\Throwable) { $body = null; }
                if ($body !== null) $out[(int) $i] = $body;
                continue;
            }
            if ($r->successful() && strlen($r->body()) <= self::MAX_BYTES && str_starts_with(strtolower((string) $r->header('Content-Type')), 'image/')) $out[(int) $i] = $r->body();
        }

        return $out;
    }

    /** Every Product node in a JSON-LD document (top level, a list, or an @graph). */
    private static function products(mixed $data): array
    {
        if (! is_array($data)) return [];
        if (array_is_list($data)) return array_merge(...array_map(fn ($d) => self::products($d), $data ?: [[]]));
        $type = $data['@type'] ?? null;
        $mine = in_array('Product', (array) $type, true) || $type === 'ProductGroup' ? [$data] : [];

        return array_merge($mine, isset($data['@graph']) ? self::products($data['@graph']) : []);
    }

    /** One image saved as a JPEG workspace asset; null when it is not a usable photo (too small, not an image). */
    private function save(User $user, string $bytes, string $src, string $page): ?int
    {
        $base = tempnam(sys_get_temp_dir(), 'plink-');
        $jpg = $base.'.jpg';
        try {
            file_put_contents($base, $bytes);
            $probe = json_decode(Process::timeout(20)->run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'json', $base])->output(), true);
            $w = (int) ($probe['streams'][0]['width'] ?? 0); $h = (int) ($probe['streams'][0]['height'] ?? 0);
            if ($w < 300 || $h < 300) return null;
            // One format downstream: video models and the editor all take JPEG, not every one takes WebP or AVIF.
            Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-y', '-i', $base, '-frames:v', '1', '-vf', "scale='min(2048,iw)':-2", '-q:v', '2', $jpg]);
            if (! is_file($jpg) || filesize($jpg) < 1024) return null;
            $path = sprintf('workspace-assets/%d/%s.jpg', $user->workspace_id, Str::uuid()->toString());
            $storageUrl = app(StorageService::class)->put($path, (string) file_get_contents($jpg), ['ContentType' => 'image/jpeg']);
            $name = pathinfo((string) parse_url($src, PHP_URL_PATH), PATHINFO_FILENAME) ?: 'product';

            return (int) Asset::query()->create([
                'workspace_id' => $user->workspace_id, 'asset_type' => 'image', 'title' => mb_substr($name, 0, 200).'.jpg',
                'description' => 'From '.mb_substr($page, 0, 400), 'storage_url' => $storageUrl, 'mime_type' => 'image/jpeg',
                'file_size_bytes' => filesize($jpg), 'dimensions_json' => ['width' => $w, 'height' => $h],
                'tags' => ['product_link'], 'status' => 'active', 'created_by_user_id' => $user->id,
            ])->id;
        } finally {
            @unlink($jpg);
            @unlink($base);
        }
    }

    /**
     * The body when the address answers 200 with the expected type within the size cap. Up to 3 redirects, each to a
     * public https address checked again before it is followed.
     */
    private function fetch(string $url, int $maxBytes, string $type): ?string
    {
        for ($hop = 0; $hop <= 3; $hop++) {
            $r = Http::connectTimeout(8)->timeout(20)->withOptions(['allow_redirects' => false])
                ->withHeaders(['User-Agent' => self::AGENT, 'Accept' => $type === 'image/' ? 'image/*' : $type.',*/*;q=0.5'])
                ->get($url);
            if ($r->redirect() && ($next = (string) $r->header('Location')) !== '') {
                if (str_starts_with($next, '/')) $next = 'https://'.parse_url($url, PHP_URL_HOST).$next;
                $url = PageReferenceService::publicUrl($next);
                continue;
            }
            if (! $r->successful() || strlen($r->body()) > $maxBytes) return null;
            if (! str_starts_with(strtolower((string) $r->header('Content-Type')), $type)) return null;

            return $r->body();
        }

        return null;
    }
}

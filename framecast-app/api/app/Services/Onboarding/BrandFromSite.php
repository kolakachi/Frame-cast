<?php
namespace App\Services\Onboarding;

use App\Models\{Asset, BrandKit, User};
use App\Services\Create\Industry;
use App\Services\Create\References\PageReferenceService;
use App\Services\Generation\UrlContentExtractor;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\{DB, Http, Log, RateLimiter};
use Illuminate\Support\Str;

/**
 * The Weave onboarding's "paste your website" (2026-10-09): reads the brand from its own site (name, colours, logo,
 * products, a few facts the page states, the industry) and saves it as the workspace's brand kit, so every video
 * after it is on brand without asking again. The first brief links the site, so its facts reach the first plan.
 */
class BrandFromSite
{
    public const MODEL = 'claude-haiku-4-5-20251001';
    public const DAILY = 6;

    public function read(User $user, string $url): array
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) $url = 'https://'.$url;
        $url = preg_replace('#^http://#i', 'https://', $url);
        $clean = PageReferenceService::publicUrl($url);
        $host = preg_replace('/^www\./', '', (string) parse_url($clean, PHP_URL_HOST));
        abort_if(PageReferenceService::socialHost($host) || PageReferenceService::videoSite($host), 422, 'Paste your own website, not a social media page.');
        $limit = 'onboarding-site:'.$user->workspace_id.':'.now()->toDateString();
        abort_if(RateLimiter::tooManyAttempts($limit, self::DAILY), 429, 'That is enough reads for today. Type your brand name instead.');
        RateLimiter::hit($limit, 86400);

        $text = '';
        try { $text = mb_substr(trim(app(UrlContentExtractor::class)->extract($clean)), 0, 8000); } catch (\Throwable) {}
        $html = '';
        try { $r = Http::timeout(15)->withOptions(['allow_redirects' => ['max' => 3]])->withHeaders(['User-Agent' => 'Mozilla/5.0 WyvStudio'])->get($clean); if ($r->successful()) $html = mb_substr((string) $r->body(), 0, 400000); } catch (\Throwable) {}
        abort_if($text === '' && $html === '', 422, 'That website could not be read. Type your brand name instead.');

        $dir = sys_get_temp_dir().'/onboarding-site-'.Str::uuid();
        @mkdir($dir, 0700);
        try {
            $shot = null;
            try { $shot = app(PageReferenceService::class)->screenshot($clean, $dir); } catch (\Throwable) {}
            $seen = $this->look($shot, $text ?: strip_tags($html), $host);
        } finally { foreach (glob($dir.'/*') ?: [] as $f) @unlink($f); @rmdir($dir); }

        $name = $seen['name'] ?: self::titleName($html) ?: ucfirst(explode('.', $host)[0]);
        $logo = $this->logo($user, $clean, $html, $name);
        $industry = isset(Industry::LIST[$seen['industry'] ?? '']) ? $seen['industry'] : null;
        $palette = $seen['palette'];
        DB::transaction(function () use ($user, $name, $palette, $logo, $industry, $clean, $seen) {
            $kit = self::kitFor($user->workspace_id, $name);
            $kit->forceFill(array_filter(['workspace_id' => $user->workspace_id, 'name' => $name, 'primary_color' => $palette[0] ?? null, 'secondary_color' => $palette[1] ?? null,
                'accent_color' => $palette[2] ?? null, 'logo_asset_id' => $logo?->id], fn ($v) => $v !== null))->save();
            if ($industry) DB::table('workspaces')->where('id', $user->workspace_id)->update(['industry' => $industry, 'industry_source' => 'site']);
            self::merge($user->workspace_id, ['site' => ['url' => $clean, 'name' => $name, 'products' => $seen['products'], 'facts' => $seen['facts'], 'summary' => $seen['summary'], 'read_at' => now()->toIso8601String()]]);
        });
        return ['url' => $clean, 'name' => $name, 'palette' => $palette, 'logo_url' => $logo ? app(StorageService::class)->url($logo->storage_url) : null,
            'products' => $seen['products'], 'summary' => $seen['summary'], 'industry' => $industry, 'industries' => Industry::LIST];
    }

    /** A brand name typed instead of a website. */
    public function named(User $user, string $name): array
    {
        $name = mb_substr(trim($name), 0, 80);
        abort_if($name === '', 422, 'Type your brand name.');
        self::kitFor($user->workspace_id, $name)->forceFill(['workspace_id' => $user->workspace_id, 'name' => $name])->save();
        self::merge($user->workspace_id, ['site' => ['url' => null, 'name' => $name, 'products' => [], 'facts' => [], 'summary' => '', 'read_at' => now()->toIso8601String()]]);
        return ['url' => null, 'name' => $name, 'palette' => [], 'logo_url' => null, 'products' => [], 'summary' => '', 'industry' => null, 'industries' => Industry::LIST];
    }

    /** The kit to fill: this brand's own kit, an unnamed first kit, or a new one; never another brand's kit. */
    public static function kitFor(int $workspaceId, string $name): BrandKit
    {
        $kits = BrandKit::where('workspace_id', $workspaceId)->orderBy('id')->get();
        return $kits->first(fn ($k) => mb_strtolower(trim((string) $k->name)) === mb_strtolower(trim($name)))
            ?? $kits->first(fn ($k) => trim((string) $k->name) === '')
            ?? new BrandKit(['workspace_id' => $workspaceId]);
    }

    public static function merge(int $workspaceId, array $values): array
    {
        $now = json_decode((string) DB::table('workspaces')->where('id', $workspaceId)->value('onboarding_json'), true) ?: [];
        $next = array_replace($now, $values);
        DB::table('workspaces')->where('id', $workspaceId)->update(['onboarding_json' => json_encode($next)]);
        return $next;
    }

    /** What the page shows: its name, three brand colours, products, a few facts it states, the industry. */
    private function look(?string $jpg, string $text, string $host): array
    {
        $empty = ['name' => '', 'palette' => [], 'products' => [], 'facts' => [], 'summary' => '', 'industry' => null];
        if (config('create.mode') === 'fixture' || (string) config('services.anthropic.key') === '') return $empty;
        $list = implode(', ', array_map(fn ($k, $v) => "{$k} ({$v})", array_keys(Industry::LIST), Industry::LIST));
        $content = [];
        if ($jpg && is_file($jpg)) $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode((string) file_get_contents($jpg))]];
        $content[] = ['type' => 'text', 'text' => "This is a business's own website ({$host}): a screenshot and the text read from it. Reply with JSON only: "
            .'{"name": "the brand name as the site writes it", "palette": ["#hex primary brand colour", "#hex second", "#hex accent"], '
            .'"products": ["up to 5 products or services, as named on the site"], "summary": "one sentence: what they sell and to whom", '
            .'"facts": [{"text": "a short line for a video, under 12 words", "quote": "the exact words on the page"}] (up to 5, only what the page says), '
            .'"industry": "one of: '.$list.'"}'."\n\nPage text:\n".mb_substr($text, 0, 6000)];
        try {
            $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(60)
                ->post('https://api.anthropic.com/v1/messages', ['model' => self::MODEL, 'max_tokens' => 800, 'messages' => [['role' => 'user', 'content' => $content]]]);
            if (! $r->successful()) { \App\Services\Vendors\VendorAlerts::observe('anthropic', $r->body(), $r->status()); return $empty; }
            $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
            $start = strpos($raw, '{');
            $json = $start === false ? null : json_decode(substr($raw, $start, strrpos($raw, '}') - $start + 1), true);
            if (! is_array($json)) return $empty;
            $s = fn ($v, $n) => mb_substr(trim(is_string($v) ? $v : ''), 0, $n);
            // A fact survives only if its quote is really on the page.
            $page = PageReferenceService::plain($text);
            return ['name' => $s($json['name'] ?? '', 80),
                'palette' => array_values(array_slice(array_filter((array) ($json['palette'] ?? []), fn ($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c)), 0, 3)),
                'products' => array_values(array_slice(array_filter(array_map(fn ($p) => $s($p, 60), (array) ($json['products'] ?? []))), 0, 5)),
                'facts' => collect((array) ($json['facts'] ?? []))->filter(fn ($f) => is_array($f) && trim((string) ($f['quote'] ?? '')) !== '' && str_contains($page, PageReferenceService::plain((string) $f['quote'])))
                    ->map(fn ($f) => $s($f['text'] ?? $f['quote'], 90))->filter()->unique()->take(5)->values()->all(),
                'summary' => $s($json['summary'] ?? '', 240), 'industry' => is_string($json['industry'] ?? null) ? $json['industry'] : null];
        } catch (\Throwable $e) {
            Log::warning('Onboarding site read failed', ['error' => $e->getMessage()]);
            return $empty;
        }
    }

    /** The site's own icon or logo (a raster image it declares), saved as the brand logo; null when none is usable. */
    private function logo(User $user, string $url, string $html, string $name): ?Asset
    {
        $found = [];
        foreach ([
            '/<link[^>]+rel=["\'][^"\']*apple-touch-icon[^"\']*["\'][^>]*href=["\']([^"\']+)["\']/i',
            '/<link[^>]+href=["\']([^"\']+)["\'][^>]*rel=["\'][^"\']*apple-touch-icon[^"\']*["\']/i',
            '/<img[^>]+(?:class|id|alt)=["\'][^"\']*logo[^"\']*["\'][^>]*src=["\']([^"\']+\.(?:png|jpe?g|webp)(?:\?[^"\']*)?)["\']/i',
            '/<img[^>]+src=["\']([^"\']+\.(?:png|jpe?g|webp)(?:\?[^"\']*)?)["\'][^>]*(?:class|id|alt)=["\'][^"\']*logo[^"\']*["\']/i',
            '/<link[^>]+rel=["\'][^"\']*icon[^"\']*["\'][^>]*href=["\']([^"\']+\.png(?:\?[^"\']*)?)["\']/i',
        ] as $re) if (preg_match($re, $html, $m)) $found[] = html_entity_decode($m[1]);
        foreach (array_unique($found) as $src) {
            try {
                $abs = str_starts_with($src, '//') ? 'https:'.$src : (preg_match('#^https?://#i', $src) ? $src : rtrim((string) preg_replace('#^(https://[^/]+).*$#', '$1', $url), '/').'/'.ltrim($src, '/'));
                $abs = PageReferenceService::publicUrl(preg_replace('#^http://#i', 'https://', $abs));
                $r = Http::timeout(10)->withOptions(['allow_redirects' => ['max' => 2]])->get($abs);
                $bytes = (string) $r->body();
                if (! $r->successful() || strlen($bytes) < 400 || strlen($bytes) > 2_000_000) continue;
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
                if (! $ext) continue;
                $size = @getimagesizefromstring($bytes);
                if ($size && min($size[0], $size[1]) < 48) continue;
                $path = 'workspace-assets/'.$user->workspace_id.'/'.Str::uuid().'.'.$ext;
                $stored = app(StorageService::class)->put($path, $bytes);
                return Asset::create(['workspace_id' => $user->workspace_id, 'created_by_user_id' => $user->id, 'title' => mb_substr($name.' logo', 0, 255), 'asset_type' => 'image',
                    'storage_url' => $stored, 'mime_type' => $mime, 'file_size_bytes' => strlen($bytes), 'transcription_status' => 'not_requested', 'status' => 'active',
                    'restriction_scope' => 'workspace', 'dimensions_json' => $size ? ['width' => $size[0], 'height' => $size[1]] : null,
                    'metadata_json' => ['brand_role' => 'logo', 'source' => 'onboarding_site', 'source_url' => $abs]]);
            } catch (\Throwable) { continue; }
        }
        return null;
    }

    private static function titleName(string $html): string
    {
        if (preg_match('/<meta[^>]+property=["\']og:site_name["\'][^>]*content=["\']([^"\']{2,60})["\']/i', $html, $m)) return trim(html_entity_decode($m[1]));
        if (preg_match('/<title>([^<|–—-]{2,60})/i', $html, $m)) return trim(html_entity_decode($m[1]));
        return '';
    }
}

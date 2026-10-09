<?php
namespace App\Services\Create\References;

use App\Models\{Asset, User};
use App\Services\Create\{AttachmentUploadService, ConversationService};
use App\Services\Generation\UrlContentExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http, Process, RateLimiter};
use Illuminate\Support\Str;

/**
 * A web page the user points at (their own site, a product page). The app
 * reads its text, takes a screenshot, and asks Opus what the page looks like
 * and what it claims. The screenshot is attached as a reference, never
 * footage. Claims are only suggestions, each quoted word for word from the
 * page; nothing goes on screen until the user approves it as a fact.
 */
class PageReferenceService
{
    /** Tests replace DNS resolution; production uses the system resolver. */
    public static ?\Closure $resolve = null;

    public function add(User $user, string $conversationId, string $url, int $version, string $key): Asset
    {
        app(ConversationService::class)->authorize($user, true);
        app(\App\Services\Create\AdmissionControl::class)->assertOpen();
        $old = Asset::where('workspace_id', $user->workspace_id)->where('metadata_json->create_upload_key', $key)->first();
        if ($old) {
            abort_unless(data_get($old->metadata_json, 'conversation_id') === $conversationId && data_get($old->metadata_json, 'page_source.requested_url') === $url, 409, 'This request key belongs to a different link.');
            return $old;
        }
        $clean = self::publicUrl($url);
        // A video post on a site we cannot download from is not a page to read: say so instead of capturing its sign-in
        // wall and caption (an Instagram reel became "claims" from a stranger's caption, 2026-10-08).
        if ($site = self::videoSite((string) parse_url($clean, PHP_URL_HOST))) abort(422, $site.' links cannot be used yet. Download the video and attach it with + Attach, or paste a link from X, YouTube, TikTok or Instagram.');
        $limit = 'create-reference:'.$user->workspace_id.':'.now()->toDateString();
        abort_if(RateLimiter::tooManyAttempts($limit, (int) config('create.reference_daily_limit')), 429, 'Daily reference limit reached. Try again tomorrow.');
        RateLimiter::hit($limit, 86400);

        try { $text = app(UrlContentExtractor::class)->extract($clean); }
        catch (\RuntimeException $e) { abort(422, 'That page could not be read. Paste the key facts into the message instead.'); }
        $text = mb_substr(trim($text), 0, 12000);

        $dir = sys_get_temp_dir().'/create-page-'.Str::uuid();
        mkdir($dir, 0700);
        try {
            $shot = $this->screenshot($clean, $dir);
            $host = (string) parse_url($clean, PHP_URL_HOST);
            $upload = new UploadedFile($shot, Str::limit($host, 60, '').'.jpg', 'image/jpeg', null, true);
            $asset = app(AttachmentUploadService::class)->upload($user, $conversationId, $upload, 'reference', $key, $version);
            $source = ['kind' => 'page', 'host' => $host, 'requested_url' => $url, 'url' => $clean, 'fetched_at' => now()->toIso8601String()];
            $asset->forceFill(['title' => Str::limit('Page · '.preg_replace('/^www\./', '', $host), 250, '…'),
                'metadata_json' => array_merge($asset->metadata_json ?? [], ['page_source' => $source, 'reference_source' => ['platform' => 'page', 'requested_url' => $url, 'url' => $clean]])])->save();
            try {
                $analysis = $this->analyze($shot, $text, $host);
                if ($analysis) $asset->forceFill(['metadata_json' => array_merge($asset->metadata_json ?? [], ['reference_analysis' => $analysis])])->save();
            } catch (\Throwable $e) { report($e); }
            return $asset->fresh();
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }

    /** https only, and every address the host resolves to must be public. */
    /** Video and social sites whose links cannot be read as pages or downloaded as references: their name, or null. */
    public static function videoSite(string $host): ?string
    {
        $host = strtolower(preg_replace('/^(www\.|m\.)/', '', $host));
        foreach (['facebook.com' => 'Facebook', 'fb.watch' => 'Facebook', 'threads.net' => 'Threads', 'vimeo.com' => 'Vimeo', 'snapchat.com' => 'Snapchat', 'pinterest.com' => 'Pinterest'] as $site => $name) {
            if ($host === $site || str_ends_with($host, '.'.$site)) return $name;
        }
        return null;
    }

    /** Sites where the words on a page belong to whoever posted there, not to the user: never facts for their video. */
    public static function socialHost(string $host): bool
    {
        $host = strtolower(preg_replace('/^(www\.|m\.)/', '', $host));
        foreach (['instagram.com', 'facebook.com', 'tiktok.com', 'twitter.com', 'x.com', 'threads.net', 'linkedin.com', 'youtube.com', 'youtu.be', 'reddit.com', 'pinterest.com'] as $site) {
            if ($host === $site || str_ends_with($host, '.'.$site)) return true;
        }
        return false;
    }

    public static function publicUrl(string $url): string
    {
        $p = parse_url(trim($url));
        abort_unless(is_array($p) && ($p['scheme'] ?? '') === 'https' && isset($p['host']) && ! isset($p['user']) && ! isset($p['port']), 422, 'Paste a public https web address.');
        $host = strtolower($p['host']);
        abort_if(filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost' || ! str_contains($host, '.') || str_ends_with($host, '.local') || str_ends_with($host, '.internal'), 422, 'That address is not a public website.');
        $ips = (self::$resolve ? (self::$resolve)($host) : @gethostbynamel($host)) ?: [];
        abort_unless($ips, 422, 'That website could not be found.');
        foreach ($ips as $ip) abort_unless(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), 422, 'That address is not a public website.');
        return 'https://'.$host.($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '');
    }

    public function screenshot(string $url, string $dir): string
    {
        $png = $dir.'/page.png';
        $r = Process::timeout(60)->run([(string) config('create.chromium_path', 'chromium'), '--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars',
            '--disable-extensions', '--no-first-run', '--mute-audio', '--window-size=1280,2200', '--virtual-time-budget=8000', '--screenshot='.$png, $url]);
        abort_unless($r->successful() && is_file($png) && filesize($png) > 1000, 422, 'The page could not be captured. Paste the key facts into the message instead.');
        $jpg = $dir.'/page.jpg';
        abort_unless(Process::timeout(30)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $png, '-q:v', '4', $jpg])->successful() && is_file($jpg), 422, 'The page capture could not be prepared.');
        return $jpg;
    }

    private function analyze(string $jpg, string $text, string $host): ?array
    {
        if (config('create.mode') === 'fixture' || (string) config('services.anthropic.key') === '') return ['notes' => null, 'suggested_claims' => [], 'text_excerpt' => mb_substr($text, 0, 600)];
        $prompt = "This is a screenshot of {$host} and the text read from it. The user wants a short video in the spirit of this page. "
            .'Describe the page so a video designer can match it, and list claims the page itself makes. Reply with JSON only: '
            .'{"summary": "one sentence: what the product is and who it is for", "look": "under 25 words", "palette": ["#hex", ...up to 5 from the page], '
            .'"type": "typography, under 20 words", "layout": "under 25 words", "audience": "under 15 words", "tone": "under 12 words", '
            .'"visual_ideas": ["up to 4 visuals from the page that would suit a video"], '
            .'"claims": [{"text": "short on-screen line, under 12 words", "quote": "the exact words on the page it comes from"}] (up to 8, only what the page says)}'
            ."\n\nPage text:\n".mb_substr($text, 0, 5000);
        $model = str_starts_with((string) config('create.agent_model'), 'claude-') ? (string) config('create.agent_model') : 'claude-opus-5-5';
        $r = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(90)
            ->post('https://api.anthropic.com/v1/messages', ['model' => $model, 'max_tokens' => 1800, 'output_config' => ['effort' => 'low'],
                'messages' => [['role' => 'user', 'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode(file_get_contents($jpg))]],
                    ['type' => 'text', 'text' => $prompt]]]]]);
        if (! $r->successful()) { \App\Services\Vendors\VendorAlerts::observe('anthropic', $r->body(), $r->status()); return null; }
        $raw = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $json = json_decode(substr($raw, (int) strpos($raw, '{'), strrpos($raw, '}') - (int) strpos($raw, '{') + 1), true);
        if (! is_array($json)) return null;
        $s = fn ($v, $n) => mb_substr(trim((string) $v), 0, $n);
        // A claim survives only if its quote really appears on the page, and only on the user's own kind of page: a
        // social profile's words are someone's post, never facts for this video.
        $page = self::plain($text);
        $claims = self::socialHost($host) ? [] : collect((array) ($json['claims'] ?? []))->filter(fn ($c) => is_array($c) && trim((string) ($c['quote'] ?? '')) !== '' && str_contains($page, self::plain((string) $c['quote'])))
            ->map(fn ($c) => ['text' => $s($c['text'] ?? $c['quote'], 90), 'quote' => $s($c['quote'], 200)])->filter(fn ($c) => $c['text'] !== '')->unique('text')->take(8)->values()->all();
        $u = $r->json('usage', []);
        return ['notes' => ['summary' => $s($json['summary'] ?? '', 240), 'look' => $s($json['look'] ?? '', 200),
                'palette' => array_values(array_filter(array_slice((array) ($json['palette'] ?? []), 0, 5), fn ($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $c))),
                'type' => $s($json['type'] ?? '', 160), 'layout' => $s($json['layout'] ?? '', 200), 'audience' => $s($json['audience'] ?? '', 120), 'tone' => $s($json['tone'] ?? '', 100),
                'borrow' => array_values(array_slice(array_filter(array_map(fn ($x) => $s($x, 140), (array) ($json['visual_ideas'] ?? []))), 0, 4))],
            'suggested_claims' => $claims, 'text_excerpt' => mb_substr($text, 0, 600),
            'notes_model' => $model, 'notes_cost_microusd' => (int) ceil(((int) ($u['input_tokens'] ?? 0)) * 4 + ((int) ($u['output_tokens'] ?? 0)) * 20)];
    }

    public static function plain(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(preg_replace('/[\x{2018}\x{2019}\x{201C}\x{201D}]/u', "'", $s))));
    }
}

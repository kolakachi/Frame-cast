<?php

namespace App\Services\Generation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a user-supplied URL into usable source text for script generation.
 *
 * Replaces a naive `strip_tags(response body)`. That was broken in a way that
 * produced confidently wrong videos rather than an error: strip_tags removes
 * TAGS but keeps the text inside them, so <script> bodies survived as "article
 * text". A YouTube link returned HTTP 200 with ~1.3MB of JS, the first 6,000
 * characters of `window.WIZ_global_data = {...youtube_web...}` were handed to
 * the model as the article, and it dutifully wrote "How to troubleshoot a
 * YouTube error in 60 seconds".
 *
 * Two rules here:
 *   1. Script/style/nav content is removed BEFORE tags are stripped.
 *   2. If we cannot get real prose out, we throw. Generating something
 *      unrelated is worse than telling the user the link didn't work.
 */
class UrlContentExtractor
{
    /** Below this many characters of extracted prose we treat it as a failure. */
    public const MIN_CONTENT_CHARS = 200;

    public const MAX_CONTENT_CHARS = 6000;

    /** A real browser UA — many sites serve a JS shell or 403 to obvious bots. */
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /**
     * @throws RuntimeException with a user-facing message when the URL yields
     *                          nothing usable.
     */
    public function extract(string $url): string
    {
        $url = trim($url);

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            throw new RuntimeException("That doesn't look like a valid web address: {$url}");
        }

        $isYouTube = $this->isYouTube($url);

        if ($isYouTube) {
            $content = $this->extractYouTube($url);
        } else {
            $content = $this->extractArticle($url);

            // A plain HTTP fetch only sees the JS shell on a client-rendered
            // site, and nothing at all behind a login wall. Before failing,
            // retry through the headless-browser renderer, which is in the
            // stack precisely for this.
            if ($content === null || mb_strlen($content) < self::MIN_CONTENT_CHARS) {
                $content = $this->extractViaRenderer($url) ?? $content;
            }
        }

        // YouTube is judged on having a real title (extractYouTube returns null
        // otherwise) rather than length — a title IS the topic, and the length
        // gate would otherwise be satisfied by our own appended instructions.
        $lenientLength = $isYouTube || $this->isLoginWalledSocial($url);

        if ($content === null || (! $lenientLength && mb_strlen($content) < self::MIN_CONTENT_CHARS)) {
            Log::warning('UrlContentExtractor: no usable content', [
                'url'    => $url,
                'length' => $content === null ? 0 : mb_strlen($content),
            ]);

            throw new RuntimeException(
                "We couldn't read enough content from {$url} to write a script. ".
                'Some sites (and paywalled or login-only pages) block automated reading. '.
                'Try pasting the text directly instead.'
            );
        }

        return Str::limit($content, self::MAX_CONTENT_CHARS, '');
    }

    // ── YouTube ───────────────────────────────────────────────────────────

    private function isYouTube(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be');
    }

    /**
     * YouTube serves an app shell, so scraping the page body yields nothing but
     * JS. oEmbed is a small documented endpoint that reliably returns the real
     * title and channel; the page's meta description adds the video blurb.
     * That's the topic, which is what the script actually needs — we do not
     * have transcript access without an API key, and we say so rather than
     * inventing detail.
     */
    private function extractYouTube(string $url): ?string
    {
        try {
            $oembed = Http::timeout(12)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get('https://www.youtube.com/oembed', ['url' => $url, 'format' => 'json']);

            if (! $oembed->ok()) {
                return null;
            }

            $title  = trim((string) $oembed->json('title', ''));
            $author = trim((string) $oembed->json('author_name', ''));

            if ($title === '') {
                return null;
            }

            $parts = ["Video title: {$title}"];
            if ($author !== '') {
                $parts[] = "Channel: {$author}";
            }

            if ($description = $this->youTubeDescription($url)) {
                $parts[] = "Video description: {$description}";
            }

            // Tell the model what it does and does not have, so it builds the
            // video around the stated topic instead of speculating on detail
            // it can't see.
            $parts[] = 'Write the script about the topic named above. '.
                'The full transcript is not available, so cover the subject generally '.
                'and do not invent quotes, statistics, or specific claims from the video.';

            return implode("\n", $parts);
        } catch (\Throwable $e) {
            Log::warning('UrlContentExtractor: YouTube lookup failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function youTubeDescription(string $url): ?string
    {
        try {
            $body = (string) Http::timeout(12)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get($url)
                ->body();

            if (preg_match('/<meta[^>]+name="description"[^>]+content="([^"]*)"/i', $body, $m)) {
                $description = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));

                // A watch page often falls back to YouTube's site-wide blurb.
                // Feeding that to the model is worse than no description — it
                // is the same class of noise that caused the original bug.
                if ($description === '' || $this->isYouTubeBoilerplate($description)) {
                    return null;
                }

                return $description;
            }
        } catch (\Throwable) {
            // Description is a bonus — the title alone is still usable.
        }

        return null;
    }

    /** YouTube's generic site description, served when a video has none of its own. */
    private function isYouTubeBoilerplate(string $description): bool
    {
        return str_contains($description, 'Enjoy the videos and music you love')
            || str_contains($description, 'share it all with friends, family');
    }

    // ── Headless-browser fallback ─────────────────────────────────────────

    /**
     * Second attempt through the in-house Chromium renderer
     * (framecast-app/renderer). It executes JS, so it sees SPA content that a
     * plain fetch cannot, and it surfaces meta tags even when the body is a
     * login wall.
     */
    private function extractViaRenderer(string $url): ?string
    {
        $base = rtrim((string) config('services.renderer.url', ''), '/');
        if ($base === '') {
            return null;
        }

        try {
            $response = Http::timeout(35)->get($base.'/render', ['url' => $url]);
            if (! $response->ok()) {
                return null;
            }

            $title = trim((string) $response->json('title', ''));
            $desc  = trim((string) $response->json('description', ''));
            $text  = trim(preg_replace('/\s+/', ' ', (string) $response->json('text', '')) ?? '');
        } catch (\Throwable $e) {
            Log::warning('UrlContentExtractor: renderer failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        // Social posts sit behind a login wall: the body text is sign-up
        // furniture, but the meta description carries the actual caption.
        // Treat them like YouTube — use the caption, and say plainly that the
        // full post wasn't readable rather than padding with boilerplate.
        if ($this->isLoginWalledSocial($url)) {
            if ($desc === '') {
                return null;
            }

            return implode("\n", [
                "Social post caption: {$desc}",
                'Write the script about the topic of that caption. The full post could not be '
                .'read, so cover the subject generally and do not invent quotes, statistics, or '
                .'details that are not in the caption.',
            ]);
        }

        $parts = [];
        if ($title !== '') {
            $parts[] = "Title: {$title}";
        }
        if ($desc !== '' && ! str_contains($text, $desc)) {
            $parts[] = $desc;
        }
        if ($text !== '') {
            $parts[] = $text;
        }

        $combined = trim(implode("\n\n", $parts));

        return $combined !== '' ? $combined : null;
    }

    /**
     * Hosts that serve a sign-in wall to logged-out readers, where the only
     * usable signal is the meta description.
     */
    private function isLoginWalledSocial(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (['instagram.com', 'facebook.com', 'tiktok.com', 'twitter.com', 'x.com', 'threads.net', 'linkedin.com'] as $needle) {
            if (str_contains($host, $needle)) {
                return true;
            }
        }

        return false;
    }

    // ── Generic pages ─────────────────────────────────────────────────────

    private function extractArticle(string $url): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html,application/xhtml+xml'])
                ->get($url);

            if (! $response->ok()) {
                return null;
            }

            $html = (string) $response->body();
        } catch (\Throwable $e) {
            Log::warning('UrlContentExtractor: fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        $title = $this->metaTitle($html);
        $body  = $this->htmlToText($html);

        if ($body === '') {
            return null;
        }

        return $title !== null ? "Title: {$title}\n\n{$body}" : $body;
    }

    private function metaTitle(string $html): ?string
    {
        foreach ([
            '/<meta[^>]+property="og:title"[^>]+content="([^"]*)"/i',
            '/<title[^>]*>(.*?)<\/title>/is',
        ] as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
                if ($title !== '') {
                    return $title;
                }
            }
        }

        return null;
    }

    /**
     * Strip non-prose regions BEFORE removing tags — this is the whole bug.
     * strip_tags() drops the tag but keeps its contents, so a <script> body
     * becomes "text" unless it is removed first.
     */
    private function htmlToText(string $html): string
    {
        // Kill entire elements whose contents are never prose.
        $html = preg_replace(
            '#<(script|style|noscript|svg|iframe|form|nav|header|footer|aside|template)\b[^>]*>.*?</\1>#is',
            ' ',
            $html
        ) ?? $html;

        // Prefer the main article region when the page marks one.
        foreach (['#<article\b[^>]*>(.*?)</article>#is', '#<main\b[^>]*>(.*?)</main>#is'] as $pattern) {
            if (preg_match($pattern, $html, $m) && mb_strlen(strip_tags($m[1])) >= self::MIN_CONTENT_CHARS) {
                $html = $m[1];
                break;
            }
        }

        // Keep block boundaries as spaces so words don't run together.
        $html = preg_replace('#<(p|br|div|li|h[1-6]|tr)\b[^>]*>#i', ' ', $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}

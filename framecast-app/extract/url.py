"""
URL → article text.

Replaces the regex boilerplate-stripping in PHP's UrlContentExtractor with
trafilatura, which is purpose-built for this: it finds the article body and
drops navigation, comments, cookie banners and footers, rather than
approximating that with tag removal.

JS-rendered pages are still handed to the existing Node renderer service. That
keeps exactly one Chromium in the stack instead of shipping a second one here,
and reuses code that is already hardened and deployed. Replacing that call with
Playwright is a later step, not a prerequisite.
"""

from __future__ import annotations

import os

import httpx
import trafilatura

from ssrf import assert_fetchable

# A real browser UA — bot agents get JS shells or 403s from many sites.
USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0 Safari/537.36"
)

MIN_CONTENT_CHARS = 200
MAX_CONTENT_CHARS = 6000

RENDERER_URL = os.environ.get("RENDERER_URL", "http://renderer:3000")

# Hosts that serve a sign-in wall to logged-out readers. Their body text is
# sign-up furniture; the real content is the meta description (the caption).
LOGIN_WALLED = (
    "instagram.com", "facebook.com", "tiktok.com",
    "twitter.com", "x.com", "threads.net", "linkedin.com",
)


def _is_login_walled(url: str) -> bool:
    return any(host in url.lower() for host in LOGIN_WALLED)


def _via_renderer(url: str) -> dict | None:
    """Ask the headless-browser service for a JS-rendered view of the page."""
    try:
        with httpx.Client(timeout=40) as client:
            response = client.get(f"{RENDERER_URL.rstrip('/')}/render", params={"url": url})
            if response.status_code != 200:
                return None
            return response.json()
    except Exception:  # noqa: BLE001 - the fallback is best-effort by design
        return None


def extract(url: str) -> dict:
    """
    Return {title, text, chars, source} or raise ValueError with a message the
    end user can act on.

    Failing loudly is deliberate: a video built from a login wall or a JS shell
    is worse than telling someone the link didn't work.
    """
    assert_fetchable(url)  # raises BlockedUrl for anything not publicly reachable

    # Social posts never yield article text — go straight to the renderer, whose
    # metadata carries the caption.
    if not _is_login_walled(url):
        try:
            with httpx.Client(
                timeout=20, follow_redirects=True, headers={"User-Agent": USER_AGENT}
            ) as client:
                html = client.get(url).text

            extracted = trafilatura.extract(
                html,
                include_comments=False,
                include_tables=True,
                favor_precision=True,
            )
            if extracted and len(extracted) >= MIN_CONTENT_CHARS:
                meta = trafilatura.extract_metadata(html)
                title = getattr(meta, "title", None) if meta else None

                return {
                    "source": "static",
                    "title": title,
                    "text": extracted[:MAX_CONTENT_CHARS],
                    "chars": len(extracted),
                }
        except Exception:  # noqa: BLE001 - fall through to the renderer
            pass

    rendered = _via_renderer(url)
    if not rendered:
        raise ValueError(
            f"We couldn't read enough content from {url} to write a script. Some sites "
            "(and paywalled or login-only pages) block automated reading. Try pasting "
            "the text directly instead."
        )

    title = (rendered.get("title") or "").strip()
    description = (rendered.get("description") or "").strip()
    body = (rendered.get("text") or "").strip()

    if _looks_unavailable(title, body):
        raise ValueError(
            "That post isn't available — it may have been deleted, or the account may "
            "be private. Check the link, or paste the text you want the video to cover."
        )

    if _is_login_walled(url):
        if not description:
            raise ValueError(
                f"We couldn't read {url}. The post may be private, or the link may be broken."
            )
        return {
            "source": "renderer:social",
            "title": title or None,
            "text": (
                f"Social post caption: {description}\n"
                "Write the script about the topic of that caption. The full post could not "
                "be read, so cover the subject generally and do not invent quotes, "
                "statistics, or details that are not in the caption."
            ),
            "chars": len(description),
        }

    combined = "\n\n".join(part for part in (title, description, body) if part)
    if len(combined) < MIN_CONTENT_CHARS:
        raise ValueError(
            f"We couldn't read enough content from {url} to write a script. Some sites "
            "(and paywalled or login-only pages) block automated reading. Try pasting "
            "the text directly instead."
        )

    return {
        "source": "renderer",
        "title": title or None,
        "text": combined[:MAX_CONTENT_CHARS],
        "chars": len(combined),
    }


def _looks_unavailable(title: str, text: str) -> bool:
    haystack = f"{title} {text}".lower()

    return any(
        needle in haystack
        for needle in ("post isn't available", "post isnt available", "page not found", "sorry, this page")
    )

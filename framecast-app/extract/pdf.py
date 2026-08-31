"""
PDF understanding.

The job is not "get the text out" — it's to tell the caller, per page, whether
this page can be read at all, and why not. That distinction is what stops us
producing a confident video from a document we only half-read.

Every page falls into one of four states, from its text layer and its embedded
image inventory:

                    | has images          | no images
    ----------------|---------------------|--------------------------
    has text        | TEXT (with figures) | TEXT
    no text         | SCANNED             | SPARSE

`kind` answers one question only: does this page need RENDERING? Only SCANNED
does. SPARSE pages — separators, back covers, a foreword holding a single line —
have nothing a camera would help with, so they are never rendered and never
billed.

Crucially that is separate from whether we KEEP a page's text. Every page
contributes whatever text it has, including SPARSE ones. Conflating the two
silently dropped a one-line foreword from the script: too short to be a "text"
page, no images to render, so its words disappeared.
"""

from __future__ import annotations

import base64
import math
from dataclasses import dataclass, asdict
from typing import Literal

import fitz  # PyMuPDF

PageKind = Literal["text", "scanned", "sparse"]

# A page below this many characters has no meaningful text layer. Not a derived
# constant — a starting point to tune against real uploads.
MIN_CHARS_PER_PAGE = 100

# Below this ratio of alphanumeric/space characters, the "text" is almost
# certainly mojibake from a broken encoding map rather than prose. Such a page
# passes a length check but is unreadable, so treat it as scanned.
MIN_LEGIBLE_RATIO = 0.55

# Render DPI for scanned pages. 150 is readable for a vision model without
# producing needlessly large images.
RENDER_DPI = 150


@dataclass
class Page:
    number: int          # 1-based, as a human refers to it
    kind: PageKind
    chars: int
    images: int
    text: str


def _legible_ratio(text: str) -> float:
    if not text:
        return 0.0
    good = sum(1 for c in text if c.isalnum() or c.isspace())
    return good / len(text)


def classify(doc: fitz.Document) -> list[Page]:
    """Per-page inventory. Cheap — no rendering happens here."""
    pages: list[Page] = []

    for index, page in enumerate(doc):
        text = (page.get_text() or "").strip()
        images = len(page.get_images(full=True))
        chars = len(text)

        if chars >= MIN_CHARS_PER_PAGE and _legible_ratio(text) >= MIN_LEGIBLE_RATIO:
            kind: PageKind = "text"
        elif images > 0:
            # Pictures but no readable words — a scan, or a full-page figure.
            kind = "scanned"
        else:
            # Little or no text and nothing to look at. Rendering it would show
            # a camera a blank page, so we don't — but any words it does have
            # are still kept below.
            kind = "sparse"

        pages.append(Page(number=index + 1, kind=kind, chars=chars, images=images, text=text))

    return pages


def render_page_png(doc: fitz.Document, page_number: int, dpi: int = RENDER_DPI) -> bytes:
    """Rasterise one page (1-based) so a vision model can read it."""
    page = doc[page_number - 1]
    pix = page.get_pixmap(dpi=dpi)
    return pix.tobytes("png")


# A4 is ~1.41 tall:wide. Pages up to a bit beyond that render as one image;
# anything taller is sliced into A4-proportioned sections. Measured on a real
# customer file (an e-commerce product sheet exported as ONE 1:30 page): sent
# whole, the model refused outright — "I can't transcribe that document" —
# while every individual slice read cleanly. Slicing isn't an optimisation
# here, it is the difference between working and not.
SLICE_RATIO = 1.45


def slices_for_page(page: "fitz.Page") -> int:
    """How many A4-ish vertical sections this page needs (1 for normal pages)."""
    rect = page.rect
    if rect.width <= 0:
        return 1
    return max(1, math.ceil((rect.height / rect.width) / SLICE_RATIO))


def render_page_slices(
    doc: fitz.Document, page_number: int, dpi: int = RENDER_DPI, limit: int | None = None
) -> list[bytes]:
    """
    Rasterise one page as A4-proportioned slices, top to bottom.

    Each slice is rendered with a clip rect, so a 1:30 page never has to exist
    as a single 50,000px pixmap in memory (the whole-page render of that
    customer file was a 44MB PNG that OOM'd the PHP side before the vision
    model ever saw it).
    """
    page = doc[page_number - 1]
    count = slices_for_page(page)
    if limit is not None:
        count = min(count, limit)
    rect = page.rect
    slice_h = rect.height / slices_for_page(page)
    out: list[bytes] = []
    for i in range(count):
        clip = fitz.Rect(rect.x0, rect.y0 + i * slice_h, rect.x1, min(rect.y1, rect.y0 + (i + 1) * slice_h))
        pix = page.get_pixmap(dpi=dpi, clip=clip)
        out.append(pix.tobytes("png"))
    return out


def analyse(data: bytes, render_scanned: bool = False, max_render: int = 0) -> dict:
    """
    Inspect a PDF and optionally rasterise the pages that need vision.

    render_scanned is opt-in and max_render caps it, because rendering plus a
    vision call is the only expensive part of this pipeline and the caller —
    which knows the user's plan — decides how much of it to buy.
    """
    try:
        doc = fitz.open(stream=data, filetype="pdf")
    except Exception as exc:  # noqa: BLE001 - surface a clean message, not a stack
        raise ValueError(
            "We couldn't read that PDF. It may be password-protected, or saved "
            "in a format we can't open."
        ) from exc

    if doc.needs_pass:
        raise ValueError(
            "That PDF is password-protected. Remove the password and upload it again."
        )

    pages = classify(doc)
    scanned = [p for p in pages if p.kind == "scanned"]

    # Vision work is counted in UNITS — one A4-ish slice — not pages, because
    # a single 1:30 "page" is dozens of pages of actual content and billing it
    # as one was a 24x under-charge. max_render caps units, so a plan cap of 5
    # buys the top five sections of a tall page rather than five whole pages.
    unit_counts = {p.number: slices_for_page(doc[p.number - 1]) for p in scanned}
    vision_units = sum(unit_counts.values())

    renders: list[dict] = []
    if render_scanned and scanned:
        budget = max_render or vision_units
        for page in scanned:
            if budget <= 0:
                break
            pngs = render_page_slices(doc, page.number, limit=budget)
            total = unit_counts[page.number]
            for i, png in enumerate(pngs):
                renders.append({
                    "page": page.number,
                    "slice": i + 1,
                    "of": total,
                    "mime_type": "image/png",
                    "base64": base64.b64encode(png).decode("ascii"),
                })
            budget -= len(pngs)

    # Every page's text, not just the pages classified "text". A sparse page
    # still has words worth keeping; `kind` only decides what gets rendered.
    text = "\n\n".join(p.text for p in pages if p.text)

    return {
        "page_count": doc.page_count,
        "text": text,
        "chars": len(text),
        "pages": [asdict(p) | {"text": ""} for p in pages],  # per-page text omitted from the summary
        "counts": {
            "text": sum(1 for p in pages if p.kind == "text"),
            "scanned": len(scanned),
            "sparse": sum(1 for p in pages if p.kind == "sparse"),
        },
        # A4-slice equivalents needing vision — the honest quoting unit.
        "vision_units": vision_units,
        # True when some of the document is only readable via vision. The caller
        # must not present a text-only script as covering the whole document.
        "partial": bool(scanned),
        "renders": renders,
    }

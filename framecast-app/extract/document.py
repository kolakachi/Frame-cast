"""
Documents for Weave (2026-10-09): a PDF, Word or PowerPoint file read into what a video can use.

Two calls, so full-size images never travel unless they are used:

  analyse(data, filename)  -> pages (each in parts when tall), pictures (each in parts when tall), small
                              thumbnails, per-page text, and how much of it only vision can read.
  render(data, filename, items) -> full-quality images of the parts the user ticked.

Word and PowerPoint are turned into PDF first (LibreOffice), so everything after that is one path.

Tall pages and tall pictures. A product sheet exported as ONE page thirty times taller than it is wide is
real (see pdf.py: the classic wizard learned it). Such a page is cut into parts of about A4 proportion, at the
blank gaps between its blocks where it has them, so a product card is not sliced through the middle; where it has
none, into even parts. A tall picture (a long infographic or product strip) is cut the same way.
"""

from __future__ import annotations

import base64
import hashlib
import os
import shutil
import subprocess
import tempfile

import fitz  # PyMuPDF

from pdf import SLICE_RATIO, classify

MAX_PAGES = int(os.environ.get("DOC_MAX_PAGES", 50))
OFFICE_TYPES = {".docx": "Word", ".pptx": "PowerPoint"}
CONVERT_TIMEOUT = int(os.environ.get("DOC_CONVERT_TIMEOUT", 120))

# A picture smaller than this is an icon, a bullet or a divider, not something a video would show.
MIN_SIDE = 150
MIN_AREA = 200 * 200
# A picture covering most of a scanned page IS the page: it is offered as a page, not as a picture.
PAGE_COVER = 0.85
# Pictures taller than this (height / width) come in parts, like tall pages.
TALL_PICTURE = 2.0
MAX_PICTURES = 60

# A page with at least this many drawn shapes likely holds a chart, a table or a diagram.
GRAPHIC_DRAWINGS = 12

THUMB_WIDTH = 240
# The width a page is sampled at to find its blank gaps: enough to see a gap, cheap even for a 1:30 page.
SAMPLE_WIDTH = 120
# Pages are rendered for use at this width (px): sharp in a 1080-wide video frame, without huge files.
PAGE_RENDER_WIDTH = 1600
PICTURE_MAX_SIDE = 2400


def to_pdf(data: bytes, filename: str) -> tuple[bytes, str]:
    """The document as PDF bytes, and what it was ('pdf', 'docx' or 'pptx')."""
    if data[:5] == b"%PDF-":
        return data, "pdf"
    ext = os.path.splitext((filename or "").lower())[1]
    if ext not in OFFICE_TYPES:
        raise ValueError("Weave reads PDF, Word (.docx) and PowerPoint (.pptx) files.")
    if data[:2] != b"PK":
        raise ValueError(f"That file doesn't look like a {OFFICE_TYPES[ext]} document. Save it again and upload it.")
    work = tempfile.mkdtemp(prefix="doc-")
    try:
        src = os.path.join(work, "in" + ext)
        with open(src, "wb") as fh:
            fh.write(data)
        # A profile of its own per call: LibreOffice refuses to share one, and the service user has no home.
        profile = "file://" + os.path.join(work, "profile")
        try:
            subprocess.run(
                ["soffice", f"-env:UserInstallation={profile}", "--headless", "--norestore", "--convert-to", "pdf", "--outdir", work, src],
                check=True, timeout=CONVERT_TIMEOUT, capture_output=True,
            )
        except subprocess.TimeoutExpired as exc:
            raise ValueError(f"That {OFFICE_TYPES[ext]} file took too long to open. Save it as PDF and upload that.") from exc
        except (subprocess.CalledProcessError, FileNotFoundError) as exc:
            raise ValueError(f"We couldn't open that {OFFICE_TYPES[ext]} file. Save it as PDF and upload that.") from exc
        out = os.path.join(work, "in.pdf")
        if not os.path.exists(out):
            raise ValueError(f"We couldn't open that {OFFICE_TYPES[ext]} file. Save it as PDF and upload that.")
        with open(out, "rb") as fh:
            return fh.read(), ext[1:]
    finally:
        shutil.rmtree(work, ignore_errors=True)


def _open(pdf: bytes) -> fitz.Document:
    try:
        doc = fitz.open(stream=pdf, filetype="pdf")
    except Exception as exc:  # noqa: BLE001
        raise ValueError("We couldn't read that document. It may be damaged, or saved in a format we can't open.") from exc
    if doc.needs_pass:
        raise ValueError("That document is password-protected. Remove the password and upload it again.")
    if doc.page_count > MAX_PAGES:
        raise ValueError(f"That document has {doc.page_count} pages. Weave reads up to {MAX_PAGES}; upload the pages you need.")
    return doc


def _blank_rows(pix: fitz.Pixmap) -> list[bool]:
    """Per row of a greyscale sample: True when the row is one flat tone (a gap between blocks)."""
    w, n, data = pix.width, pix.n, pix.samples
    stride = pix.stride
    rows = []
    for r in range(pix.height):
        row = data[r * stride: r * stride + w * n: n]
        rows.append(bool(row) and (max(row) - min(row)) <= 8)
    return rows


def cut_points(height: float, width: float, rows: list[bool]) -> list[tuple[float, float]]:
    """
    Parts of about A4 proportion down a tall area, cut in the middle of a blank band near each ideal cut when there
    is one (searched 60% to 135% of a part's height), else at the ideal cut. `rows` samples the area top to bottom.
    The widest band wins, less a little for its distance from the ideal: a wide gap is usually a break between
    sections, a narrow one the space under a heading.
    """
    if width <= 0 or height / width <= SLICE_RATIO * 1.15:
        return [(0.0, height)]
    target = width * SLICE_RATIO
    scale = len(rows) / height if rows else 0
    parts, start = [], 0.0
    while height - start > target * 1.25:
        ideal = start + target
        best, best_score = None, None
        if scale:
            lo, hi = int((start + target * 0.6) * scale), min(len(rows) - 1, int((start + target * 1.35) * scale))
            r = lo
            while r <= hi:
                if rows[r]:
                    band = r
                    while band + 1 <= hi and rows[band + 1]:
                        band += 1
                    mid = (r + band) / 2 / scale
                    score = (band - r + 1) / scale - 0.1 * abs(mid - ideal)
                    if best_score is None or score > best_score:
                        best, best_score = mid, score
                    r = band + 1
                else:
                    r += 1
        cut = best if best is not None else ideal
        parts.append((start, cut))
        start = cut
    parts.append((start, height))
    return parts


def _page_parts(page: fitz.Page) -> list[tuple[float, float]]:
    rect = page.rect
    if rect.width <= 0 or rect.height / rect.width <= SLICE_RATIO * 1.15:
        return [(0.0, rect.height)]
    sample = page.get_pixmap(matrix=fitz.Matrix(SAMPLE_WIDTH / rect.width, SAMPLE_WIDTH / rect.width), colorspace=fitz.csGRAY, alpha=False)
    return cut_points(rect.height, rect.width, _blank_rows(sample))


def _jpeg_b64(pix: fitz.Pixmap, quality: int = 72) -> str:
    return base64.b64encode(pix.tobytes("jpg", jpg_quality=quality)).decode("ascii")


def _picture_pixmap(doc: fitz.Document, xref: int) -> fitz.Pixmap:
    """A picture as plain RGB, its transparency mask applied, CMYK converted."""
    pix = fitz.Pixmap(doc, xref)
    smask = doc.extract_image(xref).get("smask") or 0
    if smask:
        try:
            pix = fitz.Pixmap(pix, fitz.Pixmap(doc, smask))
        except Exception:  # noqa: BLE001 - a broken mask still leaves a usable picture
            pass
    if pix.colorspace is None or pix.colorspace.n not in (1, 3):
        pix = fitz.Pixmap(fitz.csRGB, pix)
    if pix.alpha:
        pix = fitz.Pixmap(pix, 0)
    return pix


def _as_page(pix: fitz.Pixmap) -> tuple[fitz.Document, fitz.Page]:
    """A picture laid on a page of its own size, so it is cut and rendered exactly like a page."""
    tmp = fitz.open()
    pg = tmp.new_page(width=pix.width, height=pix.height)
    pg.insert_image(pg.rect, pixmap=pix)
    return tmp, pg


def _pictures(doc: fitz.Document, kinds: dict[int, str]) -> list[dict]:
    found, seen_xref, seen_hash = [], set(), set()
    for page in doc:
        area = page.rect.width * page.rect.height
        for img in page.get_images(full=True):
            xref, w, h = img[0], img[2], img[3]
            if xref in seen_xref or min(w, h) < MIN_SIDE or w * h < MIN_AREA:
                continue
            seen_xref.add(xref)
            # A scan's page picture is the page itself: offered under Pages.
            if kinds.get(page.number + 1) == "scanned" and area > 0 and any((r.width * r.height) / area >= PAGE_COVER for r in page.get_image_rects(xref)):
                continue
            try:
                raw = doc.extract_image(xref).get("image") or b""
                digest = hashlib.sha1(raw).hexdigest()
                if digest in seen_hash:
                    continue
                seen_hash.add(digest)
                pix = _picture_pixmap(doc, xref)
            except Exception:  # noqa: BLE001 - one unreadable picture never sinks the document
                continue
            tmp, pg = _as_page(pix)
            parts = cut_points(pix.height, pix.width, _blank_rows(fitz.Pixmap(fitz.csGRAY, pix))) if pix.height / max(1, pix.width) > TALL_PICTURE else [(0.0, float(pix.height))]
            entry = {"id": f"x{xref}", "page": page.number + 1, "width": pix.width, "height": pix.height, "parts": []}
            for i, (y0, y1) in enumerate(parts):
                clip = fitz.Rect(0, y0, pix.width, y1)
                thumb = pg.get_pixmap(matrix=fitz.Matrix(THUMB_WIDTH / pix.width, THUMB_WIDTH / pix.width), clip=clip, alpha=False)
                entry["parts"].append({"part": i + 1, "of": len(parts), "y0": round(y0, 1), "y1": round(y1, 1), "thumb": _jpeg_b64(thumb)})
            tmp.close()
            found.append(entry)
            if len(found) >= MAX_PICTURES:
                return found
    return found


def analyse(data: bytes, filename: str) -> dict:
    pdf, source = to_pdf(data, filename)
    doc = _open(pdf)
    pages = classify(doc)
    kinds = {p.number: p.kind for p in pages}
    out_pages = []
    for p in pages:
        page = doc[p.number - 1]
        parts = _page_parts(page)
        rect = page.rect
        items = []
        for i, (y0, y1) in enumerate(parts):
            clip = fitz.Rect(rect.x0, rect.y0 + y0, rect.x1, rect.y0 + y1)
            thumb = page.get_pixmap(matrix=fitz.Matrix(THUMB_WIDTH / rect.width, THUMB_WIDTH / rect.width), clip=clip, alpha=False)
            items.append({"part": i + 1, "of": len(parts), "y0": round(y0, 1), "y1": round(y1, 1), "thumb": _jpeg_b64(thumb)})
        # Shapes drawn on the page (a chart, a table's rules, a diagram): a page with many is worth offering as a
        # picture even when it holds no embedded image.
        try:
            drawings = len(page.get_drawings())
        except Exception:  # noqa: BLE001
            drawings = 0
        out_pages.append({"number": p.number, "kind": p.kind, "chars": p.chars, "images": p.images, "drawings": drawings, "text": p.text, "parts": items})
    pictures = _pictures(doc, kinds)
    scanned_units = sum(len(pg["parts"]) for pg in out_pages if pg["kind"] == "scanned")
    # Nothing to pick: no pictures, no scans and no page with a drawn chart or diagram. The words are all it has.
    text_only = not pictures and scanned_units == 0 and all(pg["drawings"] < GRAPHIC_DRAWINGS for pg in out_pages)
    result = {
        "source": source, "page_count": doc.page_count, "pages": out_pages, "pictures": pictures,
        "chars": sum(p.chars for p in pages), "scanned_units": scanned_units, "partial": scanned_units > 0, "text_only": text_only,
        # A converted Word or PowerPoint file comes back as the PDF, so later renders read the same pages.
        "pdf_base64": base64.b64encode(pdf).decode("ascii") if source != "pdf" else None,
    }
    doc.close()
    return result


def render(data: bytes, filename: str, items: list[dict]) -> list[dict]:
    """Full-quality JPEGs of the ticked parts: {"kind": "page", "number", "part"} or {"kind": "picture", "id", "part"}."""
    pdf, _ = to_pdf(data, filename)
    doc = _open(pdf)
    out = []
    for item in items[:80]:
        kind, part = item.get("kind"), int(item.get("part") or 1)
        try:
            if kind == "page":
                page = doc[int(item["number"]) - 1]
                parts = _page_parts(page)
                if not 1 <= part <= len(parts):
                    continue
                rect, (y0, y1) = page.rect, parts[part - 1]
                clip = fitz.Rect(rect.x0, rect.y0 + y0, rect.x1, rect.y0 + y1)
                pix = page.get_pixmap(matrix=fitz.Matrix(PAGE_RENDER_WIDTH / rect.width, PAGE_RENDER_WIDTH / rect.width), clip=clip, alpha=False)
            elif kind == "picture":
                xref = int(str(item["id"]).lstrip("x"))
                src = _picture_pixmap(doc, xref)
                parts = cut_points(src.height, src.width, _blank_rows(fitz.Pixmap(fitz.csGRAY, src))) if src.height / max(1, src.width) > TALL_PICTURE else [(0.0, float(src.height))]
                if not 1 <= part <= len(parts):
                    continue
                y0, y1 = parts[part - 1]
                tmp, pg = _as_page(src)
                zoom = min(1.0, PICTURE_MAX_SIDE / max(src.width, y1 - y0))
                pix = pg.get_pixmap(matrix=fitz.Matrix(zoom, zoom), clip=fitz.Rect(0, y0, src.width, y1), alpha=False)
                tmp.close()
            else:
                continue
        except Exception:  # noqa: BLE001 - a part that cannot be drawn is left out, the rest still come back
            continue
        out.append({"key": item.get("key") or f"{kind}-{item.get('number') or item.get('id')}-{part}", "mime_type": "image/jpeg",
                    "width": pix.width, "height": pix.height, "base64": _jpeg_b64(pix, 88)})
    doc.close()
    return out

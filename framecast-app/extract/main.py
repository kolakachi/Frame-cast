"""
WyvStudio extraction service — internal only.

Turns external things (web pages, PDFs) into text the script generator can use.
Owns one job so content bugs have one home, instead of being split between
hand-rolled PHP regex and a Node renderer.

NO PUBLIC PORT. It fetches arbitrary user-supplied URLs on request, so exposing
it would hand the internet a request forwarder into the compose network. See
ssrf.py for the guard that makes even internal use safe.
"""

from __future__ import annotations

import logging
import os
import signal
import sys

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from pydantic import BaseModel

import pdf as pdf_service
import url as url_service
from ssrf import BlockedUrl

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("extract")

app = FastAPI(title="WyvStudio Extract", docs_url=None, redoc_url=None)

# Refuse absurd uploads before reading them into memory.
MAX_PDF_BYTES = int(os.environ.get("MAX_PDF_BYTES", 100 * 1024 * 1024))


class UrlRequest(BaseModel):
    url: str


@app.get("/healthz")
def healthz() -> dict:
    return {"ok": True}


@app.post("/extract/url")
def extract_url(payload: UrlRequest) -> dict:
    try:
        return url_service.extract(payload.url)
    except BlockedUrl as exc:
        # 400, not 403 — this is the caller's URL being unusable, and the
        # message is safe to show a user.
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    except Exception as exc:  # noqa: BLE001
        log.exception("url extraction failed")
        raise HTTPException(status_code=500, detail="Extraction failed.") from exc


@app.post("/extract/pdf")
async def extract_pdf(
    file: UploadFile = File(...),
    render_scanned: bool = Form(False),
    max_render: int = Form(0),
) -> dict:
    """
    Inspect a PDF. Returns per-page classification plus the extracted text.

    Rendering scanned pages is opt-in and capped by the caller, because it is
    the only expensive step — the API knows the user's plan and decides how much
    of it to buy. By default this call is pure analysis and costs nothing.
    """
    data = await file.read()

    if not data:
        raise HTTPException(status_code=422, detail="That file was empty.")
    if len(data) > MAX_PDF_BYTES:
        raise HTTPException(status_code=413, detail="That PDF is too large.")

    try:
        result = pdf_service.analyse(data, render_scanned=render_scanned, max_render=max_render)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    except Exception as exc:  # noqa: BLE001
        log.exception("pdf analysis failed")
        raise HTTPException(status_code=500, detail="Could not read that PDF.") from exc

    log.info(
        "pdf analysed pages=%s text=%s scanned=%s sparse=%s rendered=%s",
        result["page_count"],
        result["counts"]["text"],
        result["counts"]["scanned"],
        result["counts"]["sparse"],
        len(result["renders"]),
    )

    return result


def _shutdown(signum, _frame):  # noqa: ANN001
    # uvicorn installs its own handlers; this exists so the container still
    # stops promptly if it is ever run without them. Same lesson as the Node
    # renderer, which ignored SIGTERM as PID 1 and made every deploy race.
    log.info("received signal %s, exiting", signum)
    sys.exit(0)


signal.signal(signal.SIGTERM, _shutdown)
signal.signal(signal.SIGINT, _shutdown)

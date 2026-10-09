FROM python:3.12-slim

# PyMuPDF ships manylinux wheels, so no build toolchain is needed — keeps the
# image small and the build fast.
WORKDIR /app

# LibreOffice turns Word and PowerPoint files into PDF for Weave (2026-10-09). The -nogui packages and a few
# common font families keep it as small as it gets while still laying out ordinary documents faithfully.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libreoffice-writer-nogui libreoffice-impress-nogui fonts-dejavu-core fonts-liberation fonts-crosextra-carlito fonts-crosextra-caladea \
 && rm -rf /var/lib/apt/lists/*

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY *.py ./

# Never run as root: this process parses untrusted PDFs and fetches
# user-supplied URLs, so it is the most likely thing in the stack to be fed a
# malicious input.
RUN useradd -u 1000 -ms /bin/bash extract
USER extract

EXPOSE 8000

# One worker: PDF parsing is CPU-bound and this box is shared with ffmpeg
# renders. Scale by raising this deliberately, not by default.
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]

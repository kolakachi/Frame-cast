FROM python:3.12-slim

# PyMuPDF ships manylinux wheels, so no build toolchain is needed — keeps the
# image small and the build fast.
WORKDIR /app

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

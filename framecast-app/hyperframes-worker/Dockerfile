FROM node:22-bookworm-slim@sha256:43ac6c60b8f89723f746e8a92ce91abd5017e627ce1ddfe4238355d3a30b772c
RUN apt-get update && apt-get install -y --no-install-recommends chromium ffmpeg fonts-dejavu-core ca-certificates && rm -rf /var/lib/apt/lists/*
WORKDIR /opt/worker
COPY package*.json ./
RUN npm ci --omit=dev
ENV HYPERFRAMES_BROWSER_PATH=/usr/bin/chromium HYPERFRAMES_NO_TELEMETRY=1 HOME=/tmp/hyperframes-home
COPY scripts ./scripts
COPY agent ./agent
COPY runtime ./runtime
RUN node scripts/verify-runtime.mjs
COPY fixtures ./fixtures
USER node
ENTRYPOINT ["flock", "-n", "/output/.worker.lock"]
CMD ["npm", "run", "smoke"]

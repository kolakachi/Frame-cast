# The HyperFrames registry (Apache-2.0) at a pinned commit, vendored for the no-network sandbox.
FROM node:22-bookworm-slim@sha256:43ac6c60b8f89723f746e8a92ce91abd5017e627ce1ddfe4238355d3a30b772c AS registry
RUN apt-get update && apt-get install -y --no-install-recommends git ca-certificates && rm -rf /var/lib/apt/lists/*
ARG HYPERFRAMES_REGISTRY_COMMIT=d218cec091a7b362eacf884f8b566afcd7f62bae
WORKDIR /src
RUN git init -q && git remote add origin https://github.com/heygen-com/hyperframes && git sparse-checkout set registry LICENSE \
 && git fetch -q --depth 1 --filter=blob:none origin $HYPERFRAMES_REGISTRY_COMMIT && git checkout -q FETCH_HEAD
COPY scripts/vendor-registry.mjs /vendor-registry.mjs
RUN node /vendor-registry.mjs --source /src/registry --out /registry --catalog /registry-catalog.json && cp /src/LICENSE /registry/LICENSE

FROM node:22-bookworm-slim@sha256:43ac6c60b8f89723f746e8a92ce91abd5017e627ce1ddfe4238355d3a30b772c
ARG CREATE_REVISION=development
ARG CREATE_SOURCE_SHA256=development
LABEL com.wyv.create.revision=$CREATE_REVISION com.wyv.create.source=$CREATE_SOURCE_SHA256
RUN apt-get update && apt-get install -y --no-install-recommends chromium ffmpeg fonts-dejavu-core ca-certificates && rm -rf /var/lib/apt/lists/*
WORKDIR /opt/worker
COPY package*.json ./
RUN npm ci --omit=dev
ENV HYPERFRAMES_BROWSER_PATH=/usr/bin/chromium HYPERFRAMES_NO_TELEMETRY=1 HOME=/tmp/hyperframes-home
COPY scripts ./scripts
COPY agent ./agent
COPY runtime ./runtime
COPY --from=registry /registry ./runtime/registry
COPY --from=registry /registry-catalog.json ./runtime/registry-catalog.json
# three.js with the mascot and prop builders as one browser script, for 3D drawn inside compositions (wyv-3d.js).
RUN node_modules/.bin/esbuild runtime/three/entry.js --bundle --format=iife --minify --alias:react=./runtime/three/shim-react.js --alias:remotion=./runtime/three/shim-remotion.js --outfile=runtime/three-wyv.js --log-level=warning
RUN node scripts/verify-runtime.mjs
COPY fixtures ./fixtures
RUN mkdir -p /sandbox-lock && chown node:node /sandbox-lock
USER node
ENTRYPOINT ["/bin/sh", "/opt/worker/scripts/sandbox-entrypoint.sh"]
CMD ["npm", "run", "smoke"]

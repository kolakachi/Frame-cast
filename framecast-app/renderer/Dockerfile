# Internal headless-browser renderer (see server.js). Debian-slim + distro
# chromium keeps the image lean and works on both amd64 and arm64.
FROM node:20-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        chromium \
        fonts-liberation \
        fonts-noto-color-emoji \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    NODE_ENV=production

WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev && npm cache clean --force
COPY server.js ./

USER node
EXPOSE 3000
CMD ["node", "server.js"]

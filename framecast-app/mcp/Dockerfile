# WyvStudio MCP sidecar (see server.js). Holds no credentials: every call
# forwards the caller's bearer token to the developer API.
FROM node:20-slim

ENV NODE_ENV=production
WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev && npm cache clean --force
COPY server.js ./

EXPOSE 3000
CMD ["node", "server.js"]

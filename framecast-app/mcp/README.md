# WyvStudio MCP server

A Node sidecar that lets an AI assistant quote, create, watch and fetch
WyvStudio videos. It wraps `/api/developer/v1` one-to-one and holds no
credentials: the client's WyvStudio API key is forwarded on every call.

Endpoint: `POST /mcp` (Streamable HTTP, stateless). Health: `GET /healthz`.

Tools: `get_capabilities`, `estimate_video`, `create_video`,
`get_video_status`, `get_video_result`. Spending is quote-bound and enforced
by the API, not by this process.

## Connect a client

Any MCP client that can send a bearer header:

```json
{
  "mcpServers": {
    "wyvstudio": {
      "url": "https://app.wyvstudio.com/mcp",
      "headers": { "Authorization": "Bearer wyv_live_…" }
    }
  }
}
```

Keys are issued from the dashboard (Creator plan and above), or via
`POST /api/v1/api-keys` with a browser session.

## Run locally

```
docker compose up -d --build api mcp
curl -s http://localhost:3001/healthz
```

Then point the MCP Inspector at `http://localhost:3001/mcp` with the
`Authorization: Bearer wyv_live_…` header.

## Environment

| Variable | Default | Meaning |
|---|---|---|
| `WYV_API_BASE_URL` | `http://api:8000` | Where the developer API is. Prod: `http://nginx`. |
| `WYV_API_HOST_HEADER` | unset | Host header to send when going through nginx. |
| `MCP_ALLOWED_HOSTS` | `localhost,127.0.0.1` | DNS-rebinding guard. Prod: `app.wyvstudio.com`. |
| `PORT` | `3000` | |

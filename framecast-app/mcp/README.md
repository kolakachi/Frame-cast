# WyvStudio MCP server

A Node sidecar that lets an AI assistant quote, create, watch and fetch
WyvStudio videos. It wraps `/api/developer/v1` one-to-one and holds no
credentials: the client's WyvStudio API key is forwarded on every call.

Endpoint: `POST /mcp` (Streamable HTTP, stateless). Health: `GET /healthz`.

Tools: 46 as of 1.8.1, listed with costs and annotations at
https://docs.wyvstudio.com/api-and-connectors/mcp-tools. Spending is
quote-bound and enforced by the API, not by this process.

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

Keys are issued from the dashboard (every plan), or via
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

## Editor discovery and bulk operations (1.4.0)

See [the editor/MCP guide](../docs/product/api-editor-mcp-guide.md) for typed
settings discovery, clearing selections, image overrides, animation history,
direct-apply rewrite semantics and quoted bulk workflows. Bulk actions run through
`propose_edits`/`apply_edits`; proposal approval is still required. They are not
unquoted shortcuts. Deployment status is tracked in the gap backlog.

### Media and voices (1.5.0)

Phase C adds `upload_asset`, `get_asset`, `clone_voice`, `preview_voice`, and
`save_voice`, audio/SFX discovery, narration attachment proposals and character
reference-update consent. See [workflow, limits and migration](../docs/product/api-media-voice-guide.md).

### UGC workflows (1.6.0)

Reference analysis, quote-bound presenter previews, resolved mode settings and
asynchronous narration guards: [Phase D guide](../docs/product/api-ugc-mcp-guide.md).
One-shot uses native speech; composed accepts Gemini voices, not clones.

## Phase E (1.7.0, local)

`prepare_delivery` checks an explicit export and returns an app handoff for public
sharing, approval requests or scheduling. It does not send or publish. Show the
app link and confirmation steps; the user must verify the export again in the app.
Assistant `schedule_post` results likewise require app confirmation and retain
`navigate`; do not report them as scheduled. See the
[delivery guide](../docs/product/api-delivery-mcp-guide.md) for excluded app-only
actions and version guarantees.

## Release verification

Current implementation and deployment gates: [release closure](../docs/product/api-release-closure.md).
The repeatable HTTP contract includes real timeout recovery, operation polling,
identical-key replay, input schema rejection and API error forwarding.

# WyvStudio MCP server

A Node sidecar that lets an AI assistant quote, create, watch and fetch
WyvStudio videos. It wraps `/api/developer/v1` one-to-one and holds no
credentials: the client's WyvStudio API key is forwarded on every call.

Endpoint: `POST /mcp` (Streamable HTTP, stateless). Health: `GET /healthz`.

Tools: 51 as of 1.8.1, listed with costs and annotations at
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

## Templates (1.10.0)

`weave_templates` lists the templates the workspace can start from (the examples on Create's empty screen until
Templates is public; every template for staff), and `weave_from_template` starts a video from one with the user's
product, how closely to follow it (exact, similar, inspired) and their photos. `weave_start` takes
`reference_match` for a reference video. Errors now carry a code per status (`not_found`, `conflict`,
`insufficient_credits`…) instead of `server_error` for everything. See the [Weave guide](../docs/product/api-weave-mcp-guide.md).

## Release verification

Current implementation and deployment gates: [release closure](../docs/product/api-release-closure.md).
The repeatable HTTP contract includes real timeout recovery, operation polling,
identical-key replay, input schema rejection and API error forwarding.

## MCP Registry

Listed in the official MCP Registry as `com.wyvstudio/wyvstudio` (published 2026-10-17). The entry is `server.json`.
When the connector changes, bump `version` in `server.json`, then:

```sh
KEY=$(openssl pkey -in ~/.config/wyvstudio/mcp-registry-key.pem -noout -text | grep -A3 "priv:" | tail -n +2 | tr -d ' :\n')
mcp-publisher login dns --domain wyvstudio.com --private-key "$KEY" && mcp-publisher publish
```

Ownership is proved by the `v=MCPv1` TXT record on wyvstudio.com; keep it. The signing key lives on the owner's
machine in `~/.config/wyvstudio/`, never in the repo.

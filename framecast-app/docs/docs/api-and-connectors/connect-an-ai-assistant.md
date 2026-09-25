---
sidebar_position: 1
title: Connect an AI assistant
description: Make WyvStudio videos from ChatGPT, Claude, Cursor or any MCP client.
---

# Connect an AI assistant

WyvStudio has an MCP server. Once an assistant is connected it can price a video, make it, watch it render and hand you the file — all from a chat. It uses your workspace's plan, credits and limits, and nothing it makes is public.

**Who can connect:** workspace owners and admins on the **Creator** plan or above. Free, Starter and UGC-pass workspaces can't use the API.

## ChatGPT

ChatGPT connectors use a sign-in flow, so you never handle a key.

1. In ChatGPT go to **Settings → Connectors → Create** (or **Add custom connector**).
2. Enter the MCP server URL: `https://app.wyvstudio.com/mcp`.
3. Leave authentication as **OAuth**. ChatGPT registers itself with WyvStudio automatically.
4. Click **Connect**. You'll land on WyvStudio, sign in if you need to, pick a workspace, and click **Allow**.
5. Back in ChatGPT, enable the connector in a chat and ask for a video.

Approving creates a private connection key in your workspace that expires after **90 days**. You'll see it under your API keys as the connector's name, and you can revoke it there any time. Reconnecting after that makes a fresh one.

## Claude

Claude web and desktop connectors use the same sign-in flow:

1. **Settings → Connectors → Add custom connector**.
2. URL: `https://app.wyvstudio.com/mcp`.
3. Click **Connect**, approve on WyvStudio, done.

## Claude Code, Cursor, and other MCP clients

Clients that can send a bearer header use an API key instead of sign-in. [Get a key](./api-keys), then add the server:

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

For Claude Code:

```bash
claude mcp add --transport http wyvstudio https://app.wyvstudio.com/mcp \
  --header "Authorization: Bearer wyv_live_…"
```

## What a conversation looks like

> **You:** Make a 30-second vertical video: three reasons a standing desk pays for itself. Stock footage is fine.
>
> **Assistant:** That'll be 18–24 credits (6–8 scenes). You have 2,053. Go ahead?
>
> **You:** Yes.
>
> **Assistant:** Started — video #4821. I'll check on it… Done. Here's the download link (valid 12 hours) and the project in WyvStudio if you want to edit it.

The assistant always quotes before it spends. WyvStudio enforces that on the server: a video can only be created against a quote you were shown, within ten minutes of it.

## What it can and can't do

| Can | Can't (yet) |
|---|---|
| Quote and make a video from a prompt or a script | Use your saved voices or characters |
| Choose stock footage, AI images or AI video | Upload footage or images |
| Set length, aspect ratio, tone | Edit scenes, publish, share or delete |
| Check progress and fetch the finished MP4 | Touch billing, members or settings |

Everything in the right column stays in the WyvStudio app. See [MCP tools](./mcp-tools) for exactly what each tool does.

## Disconnect

Revoke the connection under your API keys in WyvStudio, or remove the connector in ChatGPT or Claude. Either one ends access immediately; a removed member or a suspended workspace ends it too.

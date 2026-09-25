---
sidebar_position: 1
title: Connect an AI assistant
description: Make WyvStudio videos from ChatGPT, Claude, Cursor or any MCP client.
---

# Connect an AI assistant

WyvStudio has an MCP server. Once an assistant is connected it can price a video, make it, watch it render and hand you the file — all from a chat. It uses your workspace's plan, credits and limits, and nothing it makes is public.

**Who can connect:** workspace owners and admins on the **Creator** plan or above. Free, Starter and UGC-pass workspaces can't use the API.

## ChatGPT

ChatGPT uses a sign-in flow, so you never handle a key. Until WyvStudio is listed in ChatGPT's app directory, you add it yourself once:

1. In ChatGPT open **Settings → Security and login** and turn on **Developer mode**. Custom MCP servers only appear once it's on.
2. Go to **Settings → Plugins** and click **Create**.
3. Name it **WyvStudio**, leave **Server URL** selected and enter `https://app.wyvstudio.com/mcp`. Leave authentication as **OAuth** with no client ID or secret — ChatGPT registers itself with WyvStudio.
4. Tick the trust box and click **Create**, then **Sign in with WyvStudio**. You'll land on WyvStudio, sign in if you need to, pick a workspace, and click **Allow**.
5. Back in ChatGPT, enable WyvStudio from the tools menu in a chat and ask for a video.

Approving creates a private connection key in your workspace that expires after **90 days**. You'll see it in **Settings → API & Apps** as the connector's name, and you can disconnect it there any time. Reconnecting after that makes a fresh one.

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
| Quote and make a video from a prompt, script, URL, product description or your library images | Upload new footage or images |
| Choose stock, AI images, AI video or an audiogram, with a visual style | Delete anything |
| Use your brand kits, channels, niches, characters, music and voices | Publish, share or delete |
| Set length, aspect ratio, tone, languages, platform | Touch billing, members or settings |
| Check progress and fetch the finished MP4 | |

Everything in the right column stays in the WyvStudio app. See [MCP tools](./mcp-tools) for exactly what each tool does.

## Disconnect

Disconnect under **Settings → API & Apps** in WyvStudio, or remove the connector in ChatGPT or Claude. Either one ends access immediately; a removed member or a suspended workspace ends it too.

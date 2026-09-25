---
sidebar_position: 1
title: Connect an AI assistant
description: Make WyvStudio videos from ChatGPT, Claude, Cursor or any MCP client.
---

# Connect an AI assistant

WyvStudio has an MCP server. Once an assistant is connected it can do most of what you do in the app, from a chat: make videos from a prompt, script, article or your own images; plan and make UGC ads with your characters; create characters; edit any scene of a video and export it; and hand you the file. It uses your workspace's plan, credits and limits, quotes before it spends, and nothing it makes is public.

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
> **Assistant:** That'll be 18–24 credits (6–8 scenes), narrated by Kore. You have 2,053. Go ahead?
>
> **You:** Yes.
>
> **Assistant:** Started — video #226. I'll check on it… Done. Here's the download link (valid 12 hours) and the project in WyvStudio.
>
> **You:** Make the first scene punchier and re-export it.
>
> **Assistant:** I'll rewrite scene 1 ("stronger hook") and re-record its narration — 3 credits. Apply?
>
> **You:** Yes.
>
> **Assistant:** Applied. New export is rendering; here's the link.

The assistant always quotes before it spends. WyvStudio enforces that on the server: anything that costs credits can only run against a quote or proposal you were shown, within ten minutes of it, and never for more than it said.

## Things you can ask for

- **A video.** "Make a 45-second video from this article: https://…", "Turn this script into a 9:16 video with AI images in a watercolor style", "Use my Summer brand kit and the Alloy voice."
- **Your stuff.** "What brand kits, channels and voices do I have?", "Show me my characters", "Find the product photos in my library."
- **A UGC ad.** "Plan a 15-second UGC ad for a standing desk", "Estimate it as a one-take with Maya presenting", "Make two variants." It will ask you to confirm you have the rights to any real person's likeness or voice before it quotes.
- **A character.** "Create a character called Maya, warm, thirties, home office", "Generate a reference photo of her at a desk."
- **An edit.** "Show me video 226 and what I can change", "Rewrite scene 2 to be more educational", "Swap scene 3's visual for the desk photo", "Regenerate the music, upbeat", "Move the last scene to the front", "Export it in 1:1 as well."
- **A vague edit.** "Make video 226 more energetic", "Tighten the middle", "Give it a warmer feel." The assistant hands these to WyvStudio's own in-app assistant, which comes back with concrete actions and a total for you to approve.
- **Status.** "Is my video done?", "How many credits did that cost?", "How many UGC takes do I have left this month?"

## What it can and can't do

| Can | Can't |
|---|---|
| Make a video from a prompt, script, URL, product description or your library images, in any visual mode and style, with your brand kits, channels, niches, characters, music and voices | Upload new footage or images (use the app's library) |
| Plan, quote and make UGC ads, composed or one-take, with your characters; check your takes allowance | Restyle your own footage |
| Create and update characters and generate their images | Delete scenes, videos or characters |
| Read a video, propose and apply any scene or project change, re-voice, swap or generate visuals, animate, regenerate music, export in any ratio | Publish or share |
| Check progress, list exports, fetch the finished MP4 | Touch billing, members or settings |

Everything in the right column stays in the WyvStudio app. See [MCP tools](./mcp-tools) for exactly what each tool does and what it costs.

## Disconnect

Disconnect under **Settings → API & Apps** in WyvStudio, or remove the connector in ChatGPT or Claude. Either one ends access immediately; a removed member or a suspended workspace ends it too.

---
sidebar_position: 1
title: Connect an AI assistant
description: Make WyvStudio videos from ChatGPT, Claude, Cursor or any MCP client.
---

# Connect an AI assistant

WyvStudio has an MCP server. Once an assistant is connected it can do nearly everything you do in the app, from a chat: make videos from a prompt, script, article or your own images; plan and make UGC ads with your characters; create characters; edit any scene of a video and export it; and hand you the file. It uses your workspace's plan, credits and limits, quotes before it spends, and nothing it makes is public.

**Who can connect:** workspace owners and admins, on any plan. What you can make is what your plan allows in the app — the same limits, credits and features apply.

## ChatGPT

ChatGPT uses a sign-in flow, so you never handle a key. Until WyvStudio is listed in ChatGPT's app directory, you add it yourself once, in ChatGPT on the web:

1. Go to **Settings → Plugins → Browse directory**, click the **Add** button and choose **Add custom MCP server**.
2. Name it **WyvStudio**, leave **Server URL** selected and enter `https://app.wyvstudio.com/mcp`. Leave authentication as **OAuth** with no client ID or secret — ChatGPT registers itself with WyvStudio.
3. Tick the trust box and click **Create**, then **Sign in with WyvStudio**. You'll land on WyvStudio, sign in if you need to, pick a workspace, and click **Allow**.
4. Back in ChatGPT, enable WyvStudio from the tools menu in a chat and ask for a video.

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

The assistant makes videos with **Weave**, the same planner and builder as Create in the app. It uses the Classic tools only when you ask for Classic, a scene-by-scene edit of an existing Classic video, UGC Ads takes to test side by side, or a video longer than Weave makes.

> **You:** Make a 15-second vertical ad for our Dewbloom vitamin C serum. Warm, for dull skin.
>
> **Assistant:** I'll have Weave plan it. Planning costs a few credits; nothing else is charged until you approve a price.
>
> **Assistant:** Here's the plan: a close-up open, three quick benefits with the product in hand, and an end card with your offer, narrated by Kore. It will cost at most 140 credits; you have 2,053. Go ahead?
>
> **You:** Yes.
>
> **Assistant:** Building now. This usually takes 10 to 20 minutes; I'll check back… It's ready. Here's the preview and the link to it in WyvStudio.
>
> **You:** Make the logo bigger at the end.
>
> **Assistant:** Weave re-planned the end card with a larger logo. That change costs at most 20 credits. Apply?

The assistant always shows the price and waits for your yes. WyvStudio enforces that on the server: anything that costs credits runs only against a price you were shown, and never for more than it said. If your balance is short, the assistant offers a cheaper version (quicker build, shorter video, fewer AI shots) or the link to top up.

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
| Make a video from a prompt, script, URL, product description or your library images, in any visual mode and style, with your brand kits, channels, niches, characters, music and voices | Restyle your own footage (the My Footage lane) |
| Upload images, video, music and sound to your library and use them anywhere the app can: as a scene's visual, as music, as a character's photo | Delete scenes, videos, characters or assets |
| Plan, quote and make UGC ads, composed or one-take, with your characters or a described presenter; analyse a reference ad; check your takes allowance | Touch billing, members or settings |
| Create and update characters from a description or your photos, generate new images of them, and AI-edit a character's photo (outfit, background, hair, product in hand) | Send approval requests (the in-app scheduler and approvals stay in the app) |
| Read a video, propose and apply any scene or project change, re-voice, swap or generate visuals, animate, regenerate music, export in any ratio | |
| Show you what a scene, an animation, a character or a library file looks like, right in the chat | |
| Clone a voice from a consented sample, preview and save voices | |
| Check progress, list exports, fetch the finished MP4, recover an interrupted operation | |
| Turn a public watch link on or off, and publish or schedule a finished video to a connected YouTube, TikTok, Instagram or Facebook account, after you confirm the exact post | |

Everything in the right column stays in the WyvStudio app. Publishing only happens after the assistant shows you the account, caption and time and you say yes. See [MCP tools](./mcp-tools) for exactly what each tool does and what it costs.

## Disconnect

Disconnect under **Settings → API & Apps** in WyvStudio, or remove the connector in ChatGPT or Claude. Either one ends access immediately; a removed member or a suspended workspace ends it too.

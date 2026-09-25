---
sidebar_position: 3
title: MCP tools
description: The five tools an assistant gets, what each costs, and how spending is approved.
---

# MCP tools

Server: `https://app.wyvstudio.com/mcp` (Streamable HTTP). Auth: OAuth for ChatGPT and Claude, or a bearer API key.

| Tool | Spends credits? | Does |
|---|---|---|
| `get_capabilities` | No | Plan, balance, limits, supported inputs and per-scene prices. The key's own expiry and cap when called with a key. |
| `list_voices` | No | Narration voices: the catalogue plus your workspace's own and clones, with credits per scene. |
| `estimate_video` | No | Prices a video and returns a `quote_id` valid for 10 minutes. |
| `create_video` | **Yes**, up to the quote's max | Starts the video the quote described. Returns a video id at once. |
| `get_video_status` | No | `generating` → `exporting` → `completed`, or `failed` with a reason. Credits spent so far. |
| `get_video_result` | No | The MP4 as a private link that expires, plus duration and the project link. |

## How spending is approved

`create_video` only accepts a `quote_id` from `estimate_video`. The quote fixes exactly what will be made and the most it can cost, and it expires after ten minutes. A quote from another workspace, an expired one, or one already used is refused. The balance must cover the quote's **maximum**, not its minimum — an assistant can't top up mid-render.

Assistants are told to show you the quote first. Even if one doesn't, it can't spend more than a quote you could have seen, and each quote makes at most one video.

## Inputs for `estimate_video`

| Field | Values |
|---|---|
| `source_type` | `prompt` (WyvStudio writes the script) or `script` (your narration, used as-is) |
| `content` | The prompt or script, 10–10,000 characters |
| `visual_mode` | `stock` (cheapest), `ai_images`, `ai_video` (needs `animate_tier`) |
| `duration_seconds` | 5–600, default 60, capped by your plan |
| `animate_tier` | `quick`, `balanced`, `premium`, `seedance_lite`, `seedance_pro`, `veo_fast`, `seedance_25` |
| `animation_pacing` | `short` or `long` clips per scene (`ai_video` only) |
| `aspect_ratio` | `9:16` (default), `1:1`, `16:9` |
| `tone`, `title`, `content_goal` | Optional text |
| `voice_id` | A voice id from `list_voices`. Omit for the default voice. |

Narration is priced per scene by the voice's engine: catalogue Gemini voices 3 credits, OpenAI voices 1, your clones 2. Characters aren't selectable through the API yet.

## Waiting for a render

`create_video` returns in seconds; rendering takes minutes. The assistant polls `get_video_status` — every 15–30 seconds is right. If the conversation ends, the video still finishes, and you can find it in the app via `project_url`.

## Errors an assistant will see

| Code | Meaning | What to do |
|---|---|---|
| `quote_expired`, `quote_consumed` | The quote is stale or used | Estimate again |
| `insufficient_credits` | Balance below the quote's max | Add credits in WyvStudio |
| `key_spend_cap_reached` | This key's monthly cap | Raise the cap or wait for next month |
| `too_many_active_videos` | 3 already generating | Wait for one to finish |
| `rate_limited` | Too many calls this minute | Wait `retry_after_seconds` |
| `plan_duration_exceeded` | Longer than the plan allows | Shorten, or upgrade |
| `not_ready` | Result requested before completion | Keep polling status |
| `api_access_not_on_plan` | Plan below Creator | Upgrade |

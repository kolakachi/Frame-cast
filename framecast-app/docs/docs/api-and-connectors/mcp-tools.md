---
sidebar_position: 3
title: MCP tools
description: Every tool an assistant gets, what each costs, and how spending is approved.
---

# MCP tools

Server: `https://app.wyvstudio.com/mcp` (Streamable HTTP). Auth: OAuth for ChatGPT and Claude, or a bearer API key.

| Tool | Spends credits? | Does |
|---|---|---|
| `get_capabilities` | No | Plan, balance, limits, supported inputs and per-scene prices. The key's own expiry and cap when called with a key. |
| `get_options` | No | Every option key: visual styles, tiers, pacing, aspect ratios, platforms, languages, audiogram settings. |
| `list_brand_kits`, `list_channels`, `list_niches`, `list_caption_presets`, `list_characters` | No | Your workspace's brand kits, channels, content niches, caption presets and AI characters, by id and name. |
| `list_library` | No | Your uploaded images, music and videos, 50 a page, searchable by title. |
| `list_voices` | No | Narration voices: the catalogue plus your workspace's own and clones, with credits per scene. |
| `estimate_video` | No | Prices a video and returns a `quote_id` valid for 10 minutes. |
| `create_video` | **Yes**, up to the quote's max | Starts the video the quote described. Returns a video id at once. |
| `create_character`, `update_character` | No | Make or change a reusable AI character, from a description and/or your library photos (consent required for a real person). |
| `estimate_character_image` → `create_character_image` → `get_character_image` | Create spends the quote | A new image of a character, optionally as its new reference photo. |
| `plan_ugc` | No | Turn a script or brief into a UGC shot plan (format, shots, narration, visual direction). |
| `estimate_ugc` | No | Price a UGC plan, composed or one-take, with takes against your monthly allowance; needs your consent for any real person's likeness or voice. |
| `create_ugc` | **Yes**, up to the quote | Start the quoted takes, one video per take. |
| `get_ugc_allowance` | No | Whether UGC is on your plan, takes used and remaining this month. |
| `get_project`, `get_project_schema` | No | Everything editable about a video, its revision, and which operations are available. |
| `propose_edits` → `apply_edits` | Apply spends the proposal's total | Validate and price a list of changes, then apply them in order with a revision check. |
| `export_video`, `list_exports`, `retry_video` | No credits | Render a new export, list exports with freshness, retry a failed video. |
| `get_video_status` | No | `generating` → `exporting` → `completed`, or `failed` with a reason. Credits spent so far. |
| `get_video_result` | No | The MP4 as a private link that expires, plus duration and the project link. |

## How spending is approved

Every tool that spends credits — `create_video`, `create_ugc`, `create_character_image`, `apply_edits` — only accepts a quote or proposal id from its matching estimate. The quote fixes exactly what will be made and the most it can cost, and it expires after ten minutes. A quote from another workspace, an expired one, one already used, or one of the wrong kind is refused. The balance must cover the quote's **maximum**, not its minimum — an assistant can't top up mid-render.

Assistants are told to show you the quote first. Even if one doesn't, it can't spend more than a quote you could have seen, and each quote runs at most once.

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
| `visual_style`, `custom_visual_style` | AI modes: a style key from `get_options`, and free-text art direction |
| `image_asset_ids` | `images` source: 1–15 library image ids |
| `audiogram` | `waveform` mode: style, color, bg |
| `brand_kit_id`, `channel_id`, `niche_id`, `character_id`, `music_asset_id` | Ids from the list tools. A channel or niche fills in defaults you leave out. |
| `languages`, `platform_target`, `allow_script_edit` | Language codes (first narrates), a platform key, and whether a provided script may be lightly edited |

Source types now also include `url` (a page or article), `product_description` and `images`. The quote's `chosen` block names what was resolved — kit, channel, niche, character, music, voice — so an assistant can show you before it creates. The assistant is allowed to pick these itself when you haven't.

Narration is priced per scene by the voice's engine: catalogue Gemini voices 3 credits, OpenAI voices 1, your clones 2. Featuring a character can add per-scene reference cost that the estimate does not yet include; the dashboard's estimate has the same gap.

## UGC ads

UGC ads go through the same planner, take rules and credit checks as the UGC Ads flow in the app. The assistant plans, quotes, asks you to confirm you have the rights to any real likeness or voice, then creates. Composed ads use your characters shot by shot; one-take ads are a single continuous AI presenter, from a description or a character, optionally with a demo clip embedded. Test Pass rules (15-second takes, two takes) apply through the API exactly as in the app. Footage restyle is not available through the API yet.

## Editing a video

Editing is read, propose, apply. The assistant reads the project (every scene setting plus a readiness block and the current `revision`), proposes a list of changes, and shows you what will change and what it costs. Proposals are free and expire in ten minutes. Applying runs the changes through the editor one by one and reports each result. If the project changed in between, in the app or by another assistant, apply refuses with `revision_conflict` and the assistant reads again.

Operations: update any scene setting, reorder, add, duplicate, rewrite a script, regenerate narration, swap or search a visual, generate or edit an image, animate, cancel or revert an animation, regenerate music, change project settings, generate hooks. Deleting scenes is not available. Whole-video takes (one-take UGC, restyles) cannot have their scenes edited. Exports are separate and use your export allowance; older exports never contain newer edits.

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

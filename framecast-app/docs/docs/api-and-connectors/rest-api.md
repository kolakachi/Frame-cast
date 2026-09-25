---
sidebar_position: 4
title: REST API
description: The developer API behind the MCP server, for your own integrations.
---

# REST API

Base URL: `https://app.wyvstudio.com/api/developer/v1`
Auth: `Authorization: Bearer wyv_live_…` ([API keys](./api-keys)). JSON in, JSON out.

The MCP tools are thin wrappers over these five endpoints, so everything on the [MCP tools](./mcp-tools) page — quotes, spending rules, limits, error codes — applies here too.

OpenAPI 3.1 schema: [wyvstudio-developer-v1.yaml](/openapi/wyvstudio-developer-v1.yaml). Import it into a custom GPT (Actions, API key auth), Postman, or a code generator.

## Responses

Success: `{ "data": { … }, "meta": {} }`. Failure: `{ "error": { "code", "message", "context"? } }`. Validation failures are `422` with `error.code = validation_failed` and the field errors under `error.context.errors`.

## GET /capabilities

Plan, balance, limits, supported inputs, prices, and — when called with a key — the key's name, expiry, cap and spend this month.

```bash
curl https://app.wyvstudio.com/api/developer/v1/capabilities \
  -H "Authorization: Bearer $WYV_KEY"
```

## GET /voices

The voices a quote may name: WyvStudio's catalogue plus this workspace's own, each with `id`, `name`, `language`, `gender`, `is_cloned`, `engine` and `cost_per_scene`. `default_voice_id` is what you get when you don't choose.

## Lookups

`GET /options`, `GET /brand-kits`, `GET /channels`, `GET /niches`, `GET /caption-presets`, `GET /characters`, `GET /library?type=image|music|video&page=&q=`. All workspace-scoped and read-only; the ids they return are what the quote fields below accept.

## POST /quotes

Free. Prices a video and freezes the request.

```bash
curl -X POST https://app.wyvstudio.com/api/developer/v1/quotes \
  -H "Authorization: Bearer $WYV_KEY" -H "Content-Type: application/json" \
  -d '{
    "source_type": "prompt",
    "content": "Three reasons a standing desk pays for itself within a month.",
    "visual_mode": "stock",
    "duration_seconds": 30,
    "aspect_ratio": "9:16"
  }'
```

```json
{ "data": {
  "quote_id": "q_01m3c2zmwvz55c7r9bbb4zk3m4",
  "credits": { "min": 18, "max": 24, "mid": 21, "breakdown": { "script_and_breakdown": 0, "visual_per_scene": 0, "voice_per_scene": 3, "export": 0 } },
  "scenes": { "min": 6, "max": 8 },
  "balance": 2053, "can_afford": true, "shortage": 0,
  "expires_at": "2026-09-25T11:00:08+00:00",
  "request": { "...the validated input..." : "" }
}}
```

Fields are the same as the `estimate_video` tool: every New Video setting, with ids from the lookups. Anything not yours answers `422` with `invalid_brand_kit`, `invalid_channel`, `invalid_niche`, `invalid_character`, `invalid_music`, `invalid_images` or `invalid_voice`; a field used outside its mode is `validation_failed`. `chosen` names what the quote resolved to. `can_afford` compares the balance to `credits.max`.

## POST /videos

Creates the video a quote described. Requires the quote and an idempotency key (body `idempotency_key` or header `Idempotency-Key`). Retrying with the same key returns the same video; a new key against a used quote is `409 quote_consumed`.

```bash
curl -X POST https://app.wyvstudio.com/api/developer/v1/videos \
  -H "Authorization: Bearer $WYV_KEY" -H "Content-Type: application/json" \
  -H "Idempotency-Key: order-8812" \
  -d '{ "quote_id": "q_01m3c2zmwvz55c7r9bbb4zk3m4" }'
```

`202 Accepted`:

```json
{ "data": { "video": {
  "id": 4821, "status": "generating", "quote_id": "q_01m3c…",
  "credits": { "authorized_max": 24 },
  "project_url": "https://app.wyvstudio.com/projects/4821/editor"
}}}
```

Refusals: `404 quote_not_found`, `410 quote_expired`, `409 quote_consumed`, `409 idempotency_key_reused`, `402 insufficient_credits`, `402 key_spend_cap_reached`, `429 too_many_active_videos`. A refused create never charges and hands the quote back (except `quote_consumed`).

## Editing: GET /videos/\{id\}/project, GET …/project/schema, POST …/proposals, POST …/proposals/\{proposalId\}/apply, POST …/exports, GET …/exports, POST …/retry

Read the project and its `revision`; propose `{ revision, changes: [{ op, ... }] }` and get a `proposal_id` with per-change and total credits; apply with the proposal id (and an optional idempotency key). Apply answers `409 revision_conflict` if the project changed, and returns each change's result. Operations and their inputs are listed by the schema endpoint.

## GET /videos/\{id\}

```json
{ "data": { "video": {
  "id": 4821, "status": "generating", "title": null,
  "stage": { "current": "tts", "message": "Generating voiceover 3/8", "stages": { "script": "completed", "visuals": "completed", "tts": "running" } },
  "failure": null,
  "credits": { "authorized_max": 24, "spent": 9 },
  "retry_after_seconds": 15,
  "project_url": "…"
}}}
```

`status` is one of `generating`, `exporting`, `completed`, `failed`. On `failed`, `failure` has `message`, `stage` and `retryable` (true when the project can be retried from the app). Poll every `retry_after_seconds`; it is `null` once the video is done.

## GET /videos/\{id\}/result

`409 not_ready` until `status` is `completed`, then:

```json
{ "data": { "video": {
  "id": 4821, "status": "completed",
  "download_url": "https://app.wyvstudio.com/media/assets/9911?download=1&expires=…&signature=…",
  "download_expires_at": "2026-09-26T10:00:00+00:00",
  "file_name": "video.mp4", "aspect_ratio": "9:16", "duration_seconds": 29.6,
  "credits": { "spent": 21 },
  "project_url": "…"
}}}
```

The link is private to your workspace and expires (12 hours by default). Nothing is published or shared to produce it. Call again for a fresh link.

## Limits and errors

Rate limits and the full error-code table are on the [API keys](./api-keys#rate-limits) and [MCP tools](./mcp-tools#errors-an-assistant-will-see) pages. Every `429` carries `Retry-After` and `X-RateLimit-Remaining`.

## Versioning

`/api/developer/v1` is stable: fields are added, never renamed or removed, within v1. Breaking changes ship as `/v2` with notice.

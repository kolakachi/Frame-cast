---
sidebar_position: 2
title: API keys
description: Create, limit, rotate and revoke keys for the API and MCP clients.
---

# API keys

A key lets a script or an MCP client act as your workspace. It starts with `wyv_live_`, is shown **once** when created, and can be revoked at any time.

**Who:** workspace owners and admins, on any plan. Up to **five** active keys per workspace.

Create and manage keys in **Settings → API & Apps**. ChatGPT and Claude connectors don't need a key at all — see [Connect an AI assistant](./connect-an-ai-assistant); they appear in the same list as connected apps.

## What a key can do

Exactly what the [REST API](./rest-api) exposes: quote, create, check and fetch videos, and read your plan and balance. A key **cannot** reach billing, members, settings, publishing, sharing, deletion or the rest of the app, whatever plan you're on.

A key carries the authority of the person who created it. If that person leaves the workspace, is downgraded, or the workspace is suspended, the key stops working on the next request.

## Options when creating a key

| Option | Meaning |
|---|---|
| **Name** | How it appears in the list. Use the tool or client name. |
| **Expires in** | Optional, 1–365 days. Pilot and connector keys expire after 90 days. |
| **Monthly spend cap** | Optional. Credits the key's videos may spend per calendar month. A create that would push past it is refused with `key_spend_cap_reached`; nothing is charged. |

The key's own limits are visible to any client through `GET /capabilities`, so an assistant can explain a refusal.

## Rotate

Rotation issues a new secret with the same name, expiry and cap and revokes the old one **immediately**. Use it whenever a key may have been pasted somewhere it shouldn't. There's no grace period on purpose — the grace period is the leak.

## Revoke

Revoking stops new requests at once. Videos already generating finish and are charged as normal; you can still fetch them from the app.

## Connected apps

A ChatGPT or Claude connection shows in the same list, named after the app, with a "Connected app" badge. **Disconnect** ends its access immediately; reconnecting from the app creates a new key. Connected apps can't be rotated — reconnect instead.

## Rate limits

| Limit | Per key | Per workspace |
|---|---|---|
| Reads per minute (capabilities, status, result) | 60 | 120 |
| Writes per minute (quote, create) | 10 | 20 |
| Videos generating at once | — | 3 |

Over a limit you get `429` with a `Retry-After` header. Several keys don't multiply a workspace's allowance.

# Phase 3 — UGC through the API and MCP

25 September 2026 · Second phase in the agreed order (1 → 3 → 2 → 4).
Baseline `4812812`. Scope for approval; nothing built.

Goal: an assistant can make a UGC ad the way the UGC Ads flow does —
composed takes with a character, or a one-shot presenter video — with the
same planner, the same take and credit rules, and quote-bound approval.

## What the app does today (from `UgcController`)

| Step | Endpoint | Inputs |
|---|---|---|
| Plan | `POST /ugc/plan` | script, product, context, format, duration, language, available footage, footage asset ids, an optional reference (shape + beats) |
| Suggest | `POST /ugc/suggest` | fills a field (product, context, footage, shot, format) from a script and characters |
| Reference | `POST /ugc/reference` | derives shape and beats from a reference video asset |
| Variants | `POST /ugc/variants` | count → labelled variants with segments, per-character credits, warnings |
| Quote | `POST /ugc/quote` | format, credits per character, stale shots |
| Generate | `POST /ugc/generate` | request id, script, character ids, variants, voices, aspect ratio, product asset, language, voice key, title, **consent**, credits |
| One-shot | `POST /ugc/generate-one-shot` | script, character, cast style, fidelity, quality, presenter description, product asset(s), demo asset, setting, product, tone, language, **consent**, reviewed, credits |
| Takes | `GET /ugc/takes` | monthly take allowance and pass reservations |
| Footage | `/ugc/footage/*` | list, read, plan, restyle, produce on My Footage |

Own-face and take-pass rules live in the controller (take reservation per
request id, `plan_labels`, own-face cost) — see the UGC pass memory.

## API shape

A second quote-and-create pair, kept apart from standard videos because the
inputs, pricing and take rules differ:

| Endpoint · tool | Does |
|---|---|
| `POST /ugc/plans` · `plan_ugc` | Free. Runs the planner and returns the shot plan, suggested fields, variants and per-variant credits. Accepts everything `plan` + `variants` do, and optional `reference_asset_id`. |
| `POST /ugc/quotes` · `estimate_ugc` | Free. Prices a chosen plan + variants: credits per variant, take usage against the monthly allowance, own-face surcharge, and returns a `quote_id` (10 min) with the frozen request. Refuses when takes are exhausted. |
| `POST /ugc/videos` · `create_ugc` | Requires `quote_id` + idempotency key. Calls `generate` (composed) or `generate-one-shot` with the frozen request. **Consent is a required boolean the assistant must collect from the user in words**, echoed into the quote and re-checked at create. Returns one video id per variant. |
| `GET /videos/{id}` and `/result` | Unchanged; UGC videos are projects. |
| `GET /ugc/takes` · `get_ugc_allowance` | Takes used and remaining this month, pass reservations. |
| `GET /library?type=video` | Footage and demo assets (phase 1's library lookup, video type added). |
| Footage restyle (`/ugc/footage/*`) | **Deferred**: it is a separate lane with its own staging and moderation (E006); revisit after phase 4. |

## Rules carried over

- Take reservation by request id, so a retry cannot claim a second take.
- Own-face and cast-style costs priced in the quote; the quote's maximum is
  what create authorises.
- Consent for a real person's likeness or voice is required and recorded on
  the quote; a create without it is refused.
- Character ids, product and demo assets, footage ids: workspace-owned.

## Tests

Plan and quote are free and idempotent; exhausted takes refuse; a quote
with consent false cannot create; foreign character or asset ids are 422;
a create reserves exactly one take per variant and a retry does not
double-reserve; one-shot and composed paths both produce projects with
`api_key_id`; costs match the dashboard for the same inputs.

## Effort

About four days: planner and quote wrapping two, create and take rules one,
MCP, docs, schema and tests one.

## The UGC director through the API and MCP (MCP-BRIEF, built 2026-10-15)

Owner, 2026-10-15: "let it jump for the mcp brief, ugc is standalone now". The app's UGC chat (the Creative
Director: completeness check, pronunciations, type rules, model picker, credit hold) is open to assistants. Every
step delegates to the app's own `UgcChatController` with an internal request (`Developer\V1\UgcChatController`).

| MCP tool | Endpoint | What it does |
|---|---|---|
| `ugc_brief` | `POST /developer/v1/ugc/chats` | Free. Text plus optional type, aspect, length, link, attachments (asset ids with roles), character ids. Returns the director's question (with suggested answers) or a pitch: type, title, aspect, script, shots, credits, each video's presenter and model with the other models' prices, whether a music bed is offered |
| `ugc_reply` | `POST /developer/v1/ugc/chats/{id}/messages` | Free. Answer the question or ask for changes; returns the next question or a revised pitch |
| `ugc_make` | `POST /developer/v1/ugc/chats/{id}/make` | Paid. The pitch's message id, consent (likeness and voice rights), optional model and music. The idempotency key (default: the pitch) maps to one request id, so a retried call makes one video. Credits are held as it starts and given back if it does not deliver |
| `ugc_status` | `GET /developer/v1/ugc/chats/{id}` | The last director message and every video started: status and, when ready, its link |

The MCP server's instructions now send UGC ads (testimonial, unboxing, skit, POV hook and the rest) to the UGC
director, and keep weave_start for other videos; plan_ugc/estimate_ugc/create_ugc stay for shot-by-shot work.
Tests: `tests/Unit/DeveloperUgcChatTest.php`, `mcp/tests/editor-contract.mjs`.

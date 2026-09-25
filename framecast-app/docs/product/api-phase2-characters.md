# Phase 2 — characters through the API and MCP

25 September 2026 · Third phase in the agreed order (1 → 3 → 2 → 4).
Baseline `4812812`. Scope for approval; nothing built.

Goal: an assistant can create a reusable character and its images, then use
it in standard (phase 1) and UGC (phase 3) videos. Selecting an existing
character is already phase 1.

## What the app does today (from `CharacterController`)

| Endpoint | Inputs |
|---|---|
| `POST /characters` | name, description, reference asset id(s), consistency method, identity strength, **consent** |
| `POST /characters/{id}/generate-image` | prompt, style, model key, aspect ratio, quality, set as reference |
| `GET /character-image-generations/{id}` | generation status |
| `PATCH /characters/{id}` | edits |
| `DELETE /characters/{id}` | out of scope |
| `POST /characters/{id}/variant-preview` | UGC variant preview (phase 3 territory) |

Plan limits: `max_characters` per tier; `custom_characters` gate.

## API shape

| Endpoint · tool | Does |
|---|---|
| `POST /characters` · `create_character` | Description and/or 1–n library image ids as references, consistency method, identity strength, **consent** boolean (required when references are real people). Enforces `custom_characters` and `max_characters`. Returns the character. Free. |
| `POST /characters/{id}/images/quotes` · `estimate_character_image` | Free. Prices an image for a model key and quality; returns `quote_id`. |
| `POST /characters/{id}/images` · `create_character_image` | Requires `quote_id` + idempotency key. Starts generation; returns a generation id. |
| `GET /characters/{id}/images/{generationId}` · `get_character_image` | Status, resulting asset, whether it became the reference. |
| `PATCH /characters/{id}` · `update_character` | name, description, identity strength, set reference from an owned image. |
| `GET /characters` | Phase 1 lookup, unchanged. |

Deletion stays out. Cloning a voice for a character is voice territory and
stays out of this phase.

## Rules

Reference images must be this workspace's library assets. Consent is
recorded on the character. Unsupported model and reference combinations
fail with a clear code instead of substituting a presenter. Image generation
is quote-bound like everything else and attributed to the key.

## Tests

Limit enforced at create; consent required with references; foreign
reference asset 422; image quote → create → poll → asset; replay by
idempotency key; unsupported combination refused; plan without
`custom_characters` refused.

## Effort

About two days.

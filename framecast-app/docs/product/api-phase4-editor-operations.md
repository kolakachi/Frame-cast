# Phase 4 — editor operations through the API and MCP

25 September 2026 · Last phase (1 → 3 → 2 → 4); required before outreach.
Baseline `4812812`. Scope for approval; nothing built.

Goal: an assistant can inspect a project and change anything the editor can,
with the approval, revision and spend rules the plan (§5) sets out. Built as
read first, then one operation family at a time, each behind its own
allowlist entry and tests.

## Inventory (from `SceneController`, `ProjectController`)

**Scene settings (update):** label, script text, duration, scene type, visual
type, visual asset, character, sound asset and settings, visual prompt,
transition rule, voice profile and voice settings (audio asset, volume),
caption settings (enabled, style key, highlight mode, position, font,
highlight colour, colour, size, preset, animation, highlight style, panel
colour, backdrop, UGC headline), visual style, custom visual style, motion
(effect, intensity, fit), image generation settings, locked fields, status.

**Scene operations:** add (after a scene), reorder, duplicate, rewrite
(modes; apply or preview), swap visual (search stock or assign), generate
image, edit image (instruction, model), animate (tier, duration, motion
prompt, lip-sync engine, quality, consent), cancel / revert / use history,
regenerate voice, regenerate music (mood, duration), preview, delete (out).

**Project settings:** title, aspect ratio, channel, brand kit, music asset
and settings; hooks generation; export (aspect ratios, language, watermark);
export freshness; retry generation; resume failed.

## Delivery order and shape

### 4a. Read

| Endpoint · tool | Returns |
|---|---|
| `GET /videos/{id}/project` · `get_project` | `revision`, project settings, ordered scenes with every setting above, per-scene readiness (visual, narration, animation, stale flags), hook options, latest export and its freshness. |
| `GET /videos/{id}/project/schema` · `get_project_schema` | What may be changed on this project: allowed operations, setting enums and ranges, plan and model restrictions, and reasons anything is unavailable (whole-video takes, locked fields). |

### 4b. Proposal → approval → apply (the mechanism every write uses)

- `POST /videos/{id}/proposals` · `propose_edits`: a structured list of
  changes (scene id, setting, value, or an operation with its inputs) against
  a `revision`. Returns a `proposal_id`, the validated change set, which
  scenes regenerate, and a quote (max credits). Free, or a disclosed small
  cost when it needs the model to plan. Never applies anything.
- `POST /videos/{id}/proposals/{proposalId}/apply` · `apply_edits`: requires
  the proposal id, an idempotency key and that the project is still at the
  proposal's revision; otherwise `409 revision_conflict`. Applies the
  changes through the same services the editor uses, starts the required
  regeneration, and returns the new revision plus per-change results,
  including partial failures.
- Proposals expire in 10 minutes and are single-use, like quotes.

### 4c. Operation families, in order, each its own gate

1. **Script and structure**: script text, label, reorder, add, duplicate,
   rewrite (preview then apply). Narration marked stale where the script
   changed.
2. **Visuals**: assign a library asset, stock search and swap, generate
   image, edit image, visual style and prompt, animate with cancel and
   revert, use animation history.
3. **Narration**: voice per scene, voice settings, regenerate; lip-sync and
   stale flags preserved.
4. **Captions**: every caption setting, including presets and the UGC
   headline; project-wide apply as a convenience that expands to per-scene
   changes.
5. **Music and sound**: project music and settings, per-scene sound and
   settings, regenerate music.
6. **Motion and timing**: motion effect, intensity, fit, transition rule,
   duration only where narration does not control it.
7. **Project settings and hooks**: title, aspect ratio, channel, brand kit,
   generate hooks.
8. **Export**: `POST /videos/{id}/exports` with aspect ratios, language,
   watermark; freshness on every export; delivering an older export is an
   explicit choice; retry and resume for failed generation.

Whole-video takes (UGC one-shot, restyle) expose only what the editor allows
for them. Deletion of scenes and projects stays out.

### 4d. Parity and safety

Every operation is tested against the dashboard: same state transitions,
same ledger entries, same stale and freshness flags. A stale proposal is
refused. Partial failure returns what applied and what did not. Spend is
bounded by the proposal's quoted maximum and the key's cap.

## MCP

Tools mirror the endpoints one to one. Read and schema tools are read-only;
`propose_edits` is free and read-only in effect; `apply_edits` spends and
requires the proposal id. Descriptions tell the assistant to show the
proposal before applying.

## Effort

Two to three weeks: read and schema three days, proposal mechanism three
days, then roughly one day per operation family with tests, and two days
for export, parity and docs.
